<?php

/*
 * This file is part of NetherNet.
 * Copyright (C) 2026 Axolotl Team <https://github.com/axolotl-pm/NetherNet>
 *
 * NetherNet is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\nethernet\signaling\http;

use PHPUnit\Framework\TestCase;
use pocketmine\nethernet\FakeNegotiator;
use pocketmine\nethernet\RecordingLogger;
use pocketmine\nethernet\signaling\SignalingException;
use function fclose;
use function file_put_contents;
use function fread;
use function fwrite;
use function implode;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_export;
use function random_int;
use function str_contains;
use function stream_context_create;
use function stream_set_blocking;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function sys_get_temp_dir;
use function unlink;
use function usleep;
use const DIRECTORY_SEPARATOR;
use const OPENSSL_KEYTYPE_RSA;
use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_TLS_CLIENT;

/**
 * How the endpoint behaves when the scheme a peer used is not the one being
 * served.
 *
 * Neither direction of that mismatch explains itself. OpenSSL rejects a
 * plaintext request with nothing but "http request", and every certificate
 * problem leaves its error queue empty and puts the reason in a PHP warning
 * instead, so a listener that suppresses warnings reports the same
 * "no detail available" for a missing file, an unreadable file, and a file that
 * is not a certificate at all.
 */
final class HttpSignalingTlsTest extends TestCase{

	private const CRLF = "\r\n";

	private ?HttpSignaling $signaling = null;

	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $paths = [];

	/**
	 * @var resource[]
	 * @phpstan-var list<resource>
	 */
	private array $clients = [];

	protected function tearDown() : void{
		foreach($this->clients as $client){
			@fclose($client);
		}
		$this->clients = [];
		$this->signaling?->shutdown();
		foreach($this->paths as $path){
			@unlink($path);
		}
	}

	private function tempFile(string $name, string $contents) : string{
		$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . random_int(1000, 999999) . ".pem";
		file_put_contents($path, $contents);
		$this->paths[] = $path;

		return $path;
	}

	/**
	 * @param array<string, mixed> $tlsContext
	 */
	private function start(array $tlsContext, RecordingLogger $logger) : int{
		for($attempt = 0; $attempt < 25; ++$attempt){
			$port = random_int(20000, 60000);
			$signaling = new HttpSignaling(new FakeNegotiator("answer-sdp"), "127.0.0.1", $port, $tlsContext, $logger);

			try{
				$signaling->start();
			}catch(SignalingException $e){
				//a reserved port range is not what this test is about
				if(!str_contains($e->getMessage(), "Failed to listen")){
					throw $e;
				}
				continue;
			}

			$this->signaling = $signaling;

			return $port;
		}

		self::fail("Could not find a free port to listen on");
	}

	/**
	 * @return resource
	 */
	private function connect(int $port, bool $tls = false) : mixed{
		$context = stream_context_create(["ssl" => ["verify_peer" => false, "verify_peer_name" => false, "peer_name" => "localhost"]]);
		$client = stream_socket_client("tcp://127.0.0.1:$port", $errorCode, $errorMessage, 5, STREAM_CLIENT_CONNECT, $tls ? $context : null);
		self::assertNotFalse($client, "could not connect: $errorMessage");
		stream_set_blocking($client, false);
		$this->clients[] = $client;

		return $client;
	}

	/**
	 * Sends a request built from its head lines, so the line endings are one
	 * constant rather than an escape repeated in every literal.
	 *
	 * @param string[] $headLines
	 * @phpstan-param list<string> $headLines
	 */
	private function exchange(int $port, array $headLines, string $body = "") : string{
		$client = $this->connect($port);
		fwrite($client, implode(self::CRLF, $headLines) . self::CRLF . self::CRLF . $body);

		$response = "";
		for($attempt = 0; $attempt < 300 && !str_contains($response, self::CRLF . self::CRLF); ++$attempt){
			$this->signaling?->tick();
			$chunk = fread($client, 65536);
			if($chunk !== false){
				$response .= $chunk;
			}
			usleep(2000);
		}

		return $response;
	}

	/**
	 * A path that is simply wrong is the most common way to misconfigure this, and
	 * it is knowable before a single connection arrives.
	 */
	public function testUnreadableCertificateIsRefusedAtStartup() : void{
		$signaling = new HttpSignaling(
			new FakeNegotiator("answer-sdp"),
			"127.0.0.1",
			random_int(20000, 60000),
			["local_cert" => sys_get_temp_dir() . DIRECTORY_SEPARATOR . "definitely-not-here.pem"]
		);

		$this->expectException(SignalingException::class);
		$this->expectExceptionMessageMatches("/cannot be read/");
		$signaling->start();
	}

	/**
	 * A file that exists but is not a certificate gets past the startup check, so
	 * the handshake has to explain itself.
	 */
	public function testHandshakeFailureReportsWhyRatherThanNothing() : void{
		$logger = new RecordingLogger();
		$port = $this->start(["local_cert" => $this->tempFile("garbage", "this is not a certificate\n")], $logger);

		//a real ClientHello, so the listener commits to a handshake rather than
		//treating this as a plaintext peer to redirect
		$client = $this->connect($port, tls: true);

		for($attempt = 0; $attempt < 300; ++$attempt){
			$this->signaling?->tick();
			@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			usleep(2000);

			foreach($logger->messages as $message){
				if(str_contains($message, "TLS handshake failed")){
					self::assertStringContainsString(
						"Unable to set local cert chain file",
						$message,
						"the failure has to name the certificate; got: " . implode(" | ", $logger->messages)
					);
					self::assertStringNotContainsString("no detail available", $message);

					return;
				}
			}
		}

		self::fail("the listener never reported a handshake failure: " . implode(" | ", $logger->messages));
	}

	/**
	 * The mismatch a real client produced. Rather than refusing it, the listener
	 * names the address the peer should have used, which it can: the request is
	 * still readable, and its Host header says what the peer thinks it is talking
	 * to.
	 */
	public function testPlaintextOfferIsRedirectedPreservingTheMethod() : void{
		$logger = new RecordingLogger();
		$port = $this->start(["local_cert" => $this->certificate()], $logger);

		$response = $this->exchange($port, [
			"POST /v1/join/77?x=1 HTTP/1.1",
			"Host: example.test:$port",
			"Content-Type: application/sdp",
			"Content-Length: 3"
		], "v=0");

		//308 is the only permanent redirect that requires the retry to stay a POST,
		//and so the only one under which the offer survives it
		self::assertStringStartsWith("HTTP/1.1 308 ", $response, "got: $response\nlog: " . implode(" | ", $logger->messages));
		self::assertStringContainsString("Location: https://example.test:$port/v1/join/77?x=1", $response);
	}

	/**
	 * The capability probe gets the same treatment, so a client that follows
	 * redirects finds TLS on its first request rather than its second.
	 */
	public function testPlaintextProbeIsRedirectedToo() : void{
		$logger = new RecordingLogger();
		$port = $this->start(["local_cert" => $this->certificate()], $logger);

		$response = $this->exchange($port, ["GET /v1/join HTTP/1.1", "Host: 127.0.0.1:$port"]);

		self::assertStringStartsWith("HTTP/1.1 308 ", $response, "got: $response\nlog: " . implode(" | ", $logger->messages));
		self::assertStringContainsString("Location: https://127.0.0.1:$port/v1/join", $response);
	}

	/** A peer that really does speak TLS still gets a handshake, not a redirect. */
	public function testTlsClientStillNegotiates() : void{
		$logger = new RecordingLogger();
		$port = $this->start(["local_cert" => $this->certificate()], $logger);

		$client = $this->connect($port, tls: true);

		for($attempt = 0; $attempt < 300; ++$attempt){
			$this->signaling?->tick();
			$result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			if($result === true){
				return;
			}
			self::assertNotFalse($result, "the client handshake failed outright: " . implode(" | ", $logger->messages));
			usleep(2000);
		}

		self::fail("the TLS handshake never completed: " . implode(" | ", $logger->messages));
	}

	/** A certificate good enough to get the listener as far as a handshake. */
	private function certificate() : string{
		$config = $this->tempFile("cnf", "[req]\ndefault_bits = 2048\ndistinguished_name = dn\nprompt = no\n\n[dn]\nCN = localhost\n");
		$options = ["config" => $config, "digest_alg" => "sha256"];

		$key = openssl_pkey_new($options + ["private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA]);
		self::assertNotFalse($key);
		$request = openssl_csr_new(["commonName" => "localhost"], $key, $options);
		self::assertInstanceOf(\OpenSSLCertificateSigningRequest::class, $request);
		$signed = openssl_csr_sign($request, null, $key, 1, $options);
		self::assertInstanceOf(\OpenSSLCertificate::class, $signed);

		openssl_x509_export($signed, $certificate);
		openssl_pkey_export($key, $privateKey, null, $options);

		return $this->tempFile("cert", $certificate . $privateKey);
	}
}

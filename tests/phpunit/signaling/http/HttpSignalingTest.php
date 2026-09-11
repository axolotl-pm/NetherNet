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
use pocketmine\nethernet\negotiation\CandidateMode;
use pocketmine\nethernet\negotiation\Negotiator;
use pocketmine\nethernet\signaling\SignalingException;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function preg_match;
use function random_int;
use function str_contains;
use function stream_set_blocking;
use function stream_socket_client;
use function strlen;
use function usleep;
use const STREAM_CLIENT_CONNECT;

/**
 * Drives the endpoint over a real loopback connection.
 *
 * The listener is an ext-sockets socket so it can be moved between threads, but
 * TLS only exists on the stream API, so each accepted connection is read back as
 * a stream. That handover is the part a unit test cannot check, and it is also
 * the part that breaks silently: a mistake there shows up as a connection that
 * accepts and then never answers.
 */
final class HttpSignalingTest extends TestCase{

	private ?HttpSignaling $signaling = null;
	private int $port = 0;

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
	}

	private function start(Negotiator $negotiator, ?ServerStatusProvider $statusProvider = null) : void{
		for($attempt = 0; $attempt < 20; ++$attempt){
			$port = random_int(20000, 60000);
			$signaling = new HttpSignaling($negotiator, "127.0.0.1", $port, statusProvider: $statusProvider);

			try{
				$signaling->start();
			}catch(SignalingException){
				continue;
			}

			$this->signaling = $signaling;
			$this->port = $port;

			return;
		}

		self::fail("Could not find a free port to listen on");
	}

	/**
	 * @return resource
	 */
	private function connect() : mixed{
		$client = stream_socket_client("tcp://127.0.0.1:$this->port", $errorCode, $errorMessage, 5, STREAM_CLIENT_CONNECT);
		self::assertNotFalse($client, "could not connect: $errorMessage");
		stream_set_blocking($client, false);
		$this->clients[] = $client;

		return $client;
	}

	/**
	 * @param resource $client
	 */
	private function exchange(mixed $client, string $request) : string{
		fwrite($client, $request);

		$response = "";
		for($attempt = 0; $attempt < 400; ++$attempt){
			$this->signaling?->tick();

			$chunk = fread($client, 65536);
			if($chunk !== false && $chunk !== ""){
				$response .= $chunk;
				//every response says Connection: close, so the body ends with the stream
				if(str_contains($response, "\r\n\r\n") && feof($client)){
					break;
				}
			}

			usleep(2000);
		}

		return $response;
	}

	public function testCapabilityProbeIsAnswered() : void{
		$this->start(new FakeNegotiator("answer-sdp"));

		$response = $this->exchange($this->connect(), "GET /v1/join HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");

		self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
		self::assertStringContainsString("Connection: close", $response);
	}

	/**
	 * The status is what fills the server card, so the probe has to carry it. A
	 * client that gets a bare 200 will still offer, but the row it draws first is
	 * blank.
	 */
	public function testProbeCarriesTheStatusWhenThereIsOne() : void{
		$status = new ServerStatus("Dedicated Server", 800, "1.21.100", "Bedrock level", 3, 20);
		$this->start(new FakeNegotiator("answer-sdp"), new MutableServerStatusProvider($status));

		$response = $this->exchange($this->connect(), "GET /v1/join HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");

		self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
		self::assertStringContainsString("Content-Type: application/json\r\n", $response);
		self::assertStringEndsWith("\r\n\r\n" . $status->toJson(), $response);
	}

	/**
	 * A host that has not finished starting up has nothing to say yet, and saying
	 * nothing must not read as "this host does not speak NetherNet".
	 */
	public function testProbeStillSucceedsWithNoStatusYet() : void{
		$this->start(new FakeNegotiator("answer-sdp"), new MutableServerStatusProvider());

		$response = $this->exchange($this->connect(), "GET /v1/join HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");

		self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
		self::assertStringContainsString("Content-Length: 0\r\n", $response);
		self::assertStringNotContainsString("Content-Type:", $response);
	}

	public function testOfferIsAnsweredWithTheSdp() : void{
		$negotiator = new FakeNegotiator("v=0\r\nanswer\r\n");
		$this->start($negotiator);

		$offer = "v=0\r\noffer\r\n";
		$response = $this->exchange($this->connect(), "POST /v1/join/12345 HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/sdp\r\nContent-Length: " . strlen($offer) . "\r\n\r\n" . $offer);

		self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
		self::assertStringContainsString("Content-Type: application/sdp", $response);
		self::assertStringEndsWith("v=0\r\nanswer\r\n", $response);

		self::assertSame($offer, $negotiator->lastOffer);
		self::assertSame("12345", $negotiator->lastNetworkId);
		//a single request and response cannot carry a candidate afterwards
		self::assertSame(CandidateMode::BUNDLED, $negotiator->lastMode);
		//the offer only names the peer's private addresses; the one it signaled from is what a relay needs
		self::assertSame("127.0.0.1", $negotiator->lastPeerAddress);
	}

	public function testUnknownPathIsNotFound() : void{
		$this->start(new FakeNegotiator("answer-sdp"));

		$response = $this->exchange($this->connect(), "GET /nope HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");

		self::assertStringStartsWith("HTTP/1.1 404 ", $response);
	}

	public function testOfferWithoutTheSdpContentTypeIsRefused() : void{
		$this->start(new FakeNegotiator("answer-sdp"));

		$response = $this->exchange($this->connect(), "POST /v1/join/1 HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: text/plain\r\nContent-Length: 3\r\n\r\nabc");

		self::assertStringStartsWith("HTTP/1.1 415 ", $response);
	}

	public function testProbeEndpointRefusesAPost() : void{
		$this->start(new FakeNegotiator("answer-sdp"));

		$response = $this->exchange($this->connect(), "POST /v1/join HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Length: 0\r\n\r\n");

		self::assertStringStartsWith("HTTP/1.1 405 ", $response);
	}

	/**
	 * A body is refused on the strength of its declared length alone, before any
	 * of it is read, so an oversized offer costs nothing to turn away.
	 */
	public function testOversizedBodyIsRefusedBeforeItArrives() : void{
		$this->start(new FakeNegotiator("answer-sdp"));

		$response = $this->exchange($this->connect(), "POST /v1/join/1 HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/sdp\r\nContent-Length: 99999999\r\n\r\n");

		self::assertStringStartsWith("HTTP/1.1 413 ", $response);
	}

	/**
	 * A failed negotiation answers with the failure code and nothing else.
	 *
	 * The prose belongs in the host's log, where someone can act on it. What goes
	 * back is the one part the client can do anything with, which is what the
	 * reference implementation sends.
	 */
	public function testFailedNegotiationIsReportedAsItsErrorCode() : void{
		$this->start(new FakeNegotiator(null, failWith: \pocketmine\nethernet\negotiation\ErrorCode::ICE));

		$offer = "v=0\r\noffer\r\n";
		$response = $this->exchange($this->connect(), "POST /v1/join/7 HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/sdp\r\nContent-Length: " . strlen($offer) . "\r\n\r\n" . $offer);

		self::assertStringStartsWith("HTTP/1.1 500 ", $response);
		self::assertStringEndsWith("\r\n\r\n" . \pocketmine\nethernet\negotiation\ErrorCode::ICE->value, $response);
	}

	/** Speaking TLS to a plaintext listener is named rather than left to time out. */
	public function testTlsClientHelloToAPlaintextListenerIsRefused() : void{
		$this->start(new FakeNegotiator("answer-sdp"));

		$response = $this->exchange($this->connect(), "\x16\x03\x01\x00\x2f\x01\x00\x00\x2b\x03\x03");

		self::assertStringStartsWith("HTTP/1.1 400 ", $response);
	}

	public function testSeveralClientsAreServedInOnePass() : void{
		$this->start(new FakeNegotiator("answer-sdp"));

		$first = $this->connect();
		$second = $this->connect();

		fwrite($first, "GET /v1/join HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
		$secondResponse = $this->exchange($second, "GET /v1/join HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");

		$firstResponse = "";
		for($attempt = 0; $attempt < 200 && !str_contains($firstResponse, "\r\n\r\n"); ++$attempt){
			$this->signaling?->tick();
			$chunk = fread($first, 65536);
			if($chunk !== false){
				$firstResponse .= $chunk;
			}
			usleep(2000);
		}

		self::assertSame(1, preg_match('#^HTTP/1\.1 200 #', $firstResponse));
		self::assertSame(1, preg_match('#^HTTP/1\.1 200 #', $secondResponse));
	}

	public function testStartingTwiceIsRefused() : void{
		$this->start(new FakeNegotiator("answer-sdp"));

		$this->expectException(SignalingException::class);
		$this->signaling?->start();
	}
}

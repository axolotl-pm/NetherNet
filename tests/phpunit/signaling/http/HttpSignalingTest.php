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
use pocketmine\nethernet\info\MutableServerInfoProvider;
use pocketmine\nethernet\info\ServerInfo;
use pocketmine\nethernet\info\ServerInfoProvider;
use pocketmine\nethernet\negotiation\CandidateMode;
use pocketmine\nethernet\negotiation\Negotiator;
use pocketmine\nethernet\TestSignalingContext;
use pocketmine\nethernet\utils\InternetAddress;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function microtime;
use function preg_match;
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

	private function start(Negotiator $negotiator, ?ServerInfoProvider $infoProvider = null, ?ReverseProxy $reverseProxy = null, int $maxConnections = HttpSignaling::DEFAULT_MAX_CONNECTIONS) : void{
		$this->signaling = new HttpSignaling(new InternetAddress("127.0.0.1", 0, 4), infoProvider: $infoProvider, reverseProxy: $reverseProxy, maxConnections: $maxConnections);
		$this->signaling->start(TestSignalingContext::create($negotiator));
		$this->port = $this->signaling->getBoundAddress()->port;
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
			$this->signaling?->tick(microtime(true));

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

	/**
	 * The status is what fills the server card, so the probe has to carry it. A
	 * client that gets a bare 200 will still offer, but the row it draws first is
	 * blank. Escaping the name would still decode, but makes the one thing an
	 * operator looks for in a capture unreadable.
	 */
	public function testProbeCarriesTheStatusWhenThereIsOne() : void{
		$status = new ServerInfo("Café Server / #1", 800, "1.21.100", "Bedrock level", playerCount: 3, maxPlayerCount: 20);
		$this->start(new FakeNegotiator("answer-sdp"), new MutableServerInfoProvider($status));

		$response = $this->exchange($this->connect(), "GET /v1/join HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");

		self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
		self::assertStringContainsString("Content-Type: application/json\r\n", $response);
		self::assertStringEndsWith('"name":"Café Server / #1","protocol":800,"version":"1.21.100","level":"Bedrock level","players":3,"maxPlayers":20,"gameType":0}', $response);
	}

	/**
	 * A host that has not finished starting up has nothing to say yet, and saying
	 * nothing must not read as "this host does not speak NetherNet".
	 */
	public function testProbeStillSucceedsWithNoStatusYet() : void{
		$this->start(new FakeNegotiator("answer-sdp"), new MutableServerInfoProvider());

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

	public function testTrustedProxyHeaderReplacesThePeerAddress() : void{
		$negotiator = new FakeNegotiator("answer");
		$this->start($negotiator, reverseProxy: ReverseProxy::nginx(["127.0.0.1"]));

		$this->exchange($this->connect(), "POST /v1/join/1 HTTP/1.1\r\nHost: h\r\nX-Real-IP: 203.0.113.7\r\nContent-Type: application/sdp\r\nContent-Length: 5\r\n\r\noffer");

		self::assertSame("203.0.113.7", $negotiator->lastPeerAddress);
	}

	/**
	 * Forwarded headers from untrusted peers must be ignored to prevent IP spoofing.
	 */
	public function testProxyHeaderFromAnUntrustedPeerIsIgnored() : void{
		$negotiator = new FakeNegotiator("answer");
		$this->start($negotiator, reverseProxy: ReverseProxy::nginx(["10.0.0.0/8"]));

		$this->exchange($this->connect(), "POST /v1/join/1 HTTP/1.1\r\nHost: h\r\nX-Real-IP: 203.0.113.7\r\nContent-Type: application/sdp\r\nContent-Length: 5\r\n\r\noffer");

		self::assertSame("127.0.0.1", $negotiator->lastPeerAddress);
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
		$this->start(new FakeNegotiator(null, failWith: \pocketmine\nethernet\protocol\ErrorCode::ICE));

		$offer = "v=0\r\noffer\r\n";
		$response = $this->exchange($this->connect(), "POST /v1/join/7 HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/sdp\r\nContent-Length: " . strlen($offer) . "\r\n\r\n" . $offer);

		self::assertStringStartsWith("HTTP/1.1 500 ", $response);
		self::assertStringEndsWith("\r\n\r\n" . \pocketmine\nethernet\protocol\ErrorCode::ICE->value, $response);
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
			$this->signaling?->tick(microtime(true));
			$chunk = fread($first, 65536);
			if($chunk !== false){
				$firstResponse .= $chunk;
			}
			usleep(2000);
		}

		self::assertSame(1, preg_match('#^HTTP/1\.1 200 #', $firstResponse));
		self::assertSame(1, preg_match('#^HTTP/1\.1 200 #', $secondResponse));
	}

	/**
	 * @param resource $client
	 */
	private static function isClosedByTheServer(mixed $client) : bool{
		for($attempt = 0; $attempt < 100; ++$attempt){
			fread($client, 1);
			if(feof($client)){
				return true;
			}
			usleep(1000);
		}

		return false;
	}

	/**
	 * Clients that connect and then say nothing must not be able to keep everyone else out, so the one that
	 * has been silent longest gives up its place.
	 */
	public function testFullListenerEvictsTheClientThatHasWaitedLongest() : void{
		$this->start(new FakeNegotiator("answer-sdp"), maxConnections: 2);

		$oldest = $this->connect();
		$this->signaling?->tick(1.0);
		$newer = $this->connect();
		$this->signaling?->tick(2.0);
		$latest = $this->connect();
		$this->signaling?->tick(3.0);

		self::assertTrue(self::isClosedByTheServer($oldest), "the longest-waiting client kept its place");

		fwrite($newer, "GET /v1/join HTTP/1.1\r\nHost: h\r\n\r\n");
		fwrite($latest, "GET /v1/join HTTP/1.1\r\nHost: h\r\n\r\n");
		$this->signaling?->tick(3.1);
		self::assertStringStartsWith("HTTP/1.1 200 ", (string) fread($newer, 1024));
		self::assertStringStartsWith("HTTP/1.1 200 ", (string) fread($latest, 1024));
	}

	/**
	 * A client whose offer is already being worked on is not the one holding things up, and cutting it off
	 * would throw that work away.
	 */
	public function testFullListenerTurnsNewClientsAwayRatherThanDropRequestsInProgress() : void{
		$this->start(new FakeNegotiator(null), maxConnections: 1);

		$busy = $this->connect();
		fwrite($busy, "POST /v1/join/1 HTTP/1.1\r\nHost: h\r\nContent-Type: application/sdp\r\nContent-Length: 5\r\n\r\noffer");
		$this->signaling?->tick(1.0);

		$late = $this->connect();
		$this->signaling?->tick(2.0);

		self::assertTrue(self::isClosedByTheServer($late));
		self::assertFalse(feof($busy));
	}

	public function testStartingTwiceIsRefused() : void{
		$this->start($negotiator = new FakeNegotiator("answer-sdp"));

		$this->expectException(\LogicException::class);
		$this->signaling?->start(TestSignalingContext::create($negotiator));
	}

	/**
	 * A transport that came back after a shutdown would hold a listening socket that nothing ever services.
	 */
	public function testStartingAfterShutdownIsRefused() : void{
		$this->start($negotiator = new FakeNegotiator("answer-sdp"));
		$this->signaling?->shutdown();

		$this->expectException(\LogicException::class);
		$this->signaling?->start(TestSignalingContext::create($negotiator));
	}
}

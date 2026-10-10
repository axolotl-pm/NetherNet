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
use pocketmine\nethernet\utils\AddressBlockTracker;
use pocketmine\nethernet\utils\InternetAddress;
use function fclose;
use function fread;
use function fwrite;
use function is_resource;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_pair;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * Drives a connection over a socket pair with the clock in the test's hands, which is the only way to see
 * what it does about a client that is slow, oversized or gone.
 */
final class HttpConnectionTest extends TestCase{

	/** @var resource|null */
	private mixed $client = null;

	private FakeNegotiator $negotiator;
	private JoinHandler $handler;
	private HttpConnection $connection;

	protected function setUp() : void{
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		self::assertNotFalse($pair);
		[$this->client, $server] = $pair;
		stream_set_blocking($this->client, false);
		stream_set_blocking($server, false);

		$logger = new RecordingLogger();
		$this->negotiator = new FakeNegotiator("answer-sdp");
		$this->handler = new JoinHandler($this->negotiator, new AddressBlockTracker(), null, null, $logger);
		$this->connection = new HttpConnection(
			$server,
			null,
			new InternetAddress("192.0.2.10", 40000, 4),
			null,
			$logger,
			100.0
		);
	}

	protected function tearDown() : void{
		$this->connection->close();
		if(is_resource($this->client)){
			fclose($this->client);
		}
	}

	private function send(string $data) : void{
		self::assertNotNull($this->client);
		fwrite($this->client, $data);
	}

	private function received() : string{
		self::assertNotNull($this->client);
		$data = fread($this->client, 65536);

		return $data === false ? "" : $data;
	}

	public function testConnectionWaitsWhileTheNegotiationHasNoAnswer() : void{
		$this->handler = new JoinHandler($negotiator = new FakeNegotiator(null), new AddressBlockTracker(), null, null, new RecordingLogger());
		$this->send("POST /v1/join/1234 HTTP/1.1\r\nHost: h\r\nContent-Type: application/sdp\r\nContent-Length: 5\r\n\r\noffer");

		$this->connection->advance(100.1, $this->handler);
		$this->connection->advance(500.0, $this->handler);

		self::assertFalse($this->connection->isDone());
		self::assertSame("", $this->received());

		$negotiator->negotiation?->fail("ice failed");
		$this->connection->advance(500.1, $this->handler);
		self::assertStringStartsWith("HTTP/1.1 500 ", $this->received());
	}

	public function testBodyArrivingInPiecesIsWaitedFor() : void{
		$this->send("POST /v1/join/1234 HTTP/1.1\r\nHost: h\r\nContent-Type: application/sdp\r\nContent-Length: 5\r\n\r\nof");
		$this->connection->advance(100.1, $this->handler);

		self::assertFalse($this->connection->isDone());
		self::assertNull($this->negotiator->lastOffer);

		$this->send("fer");
		$this->connection->advance(100.2, $this->handler);
		self::assertSame("offer", $this->negotiator->lastOffer);
	}

	public function testClientThatNeverFinishesItsHeadTimesOut() : void{
		$this->send("GET /v1/join HTTP/1.1\r\n");

		$this->connection->advance(100.0 + HttpConnection::HEAD_TIMEOUT, $this->handler);
		self::assertSame("", $this->received());

		$this->connection->advance(100.0 + HttpConnection::HEAD_TIMEOUT + 0.01, $this->handler);
		self::assertStringStartsWith("HTTP/1.1 408 ", $this->received());
	}

	/**
	 * The body timeout covers the whole request, so a client cannot buy itself more time by sending its head late.
	 */
	public function testClientThatNeverFinishesItsBodyTimesOut() : void{
		$this->send("POST /v1/join/1234 HTTP/1.1\r\nHost: h\r\nContent-Type: application/sdp\r\nContent-Length: 5\r\n\r\nof");
		$this->connection->advance(104.0, $this->handler);

		$this->connection->advance(100.0 + HttpConnection::BODY_TIMEOUT, $this->handler);
		self::assertSame("", $this->received());

		$this->connection->advance(100.0 + HttpConnection::BODY_TIMEOUT + 0.01, $this->handler);
		self::assertStringStartsWith("HTTP/1.1 408 ", $this->received());
		self::assertNull($this->negotiator->lastOffer);
	}

	public function testHeadThatNeverEndsIsRefusedOnceItIsTooLarge() : void{
		$this->send("GET /v1/join HTTP/1.1\r\nX-Padding: " . str_repeat("a", HttpConnection::MAX_HEAD_SIZE));

		$this->connection->advance(100.1, $this->handler);

		self::assertStringStartsWith("HTTP/1.1 431 ", $this->received());
	}

	public function testClientThatLeavesMidRequestEndsTheConnection() : void{
		$this->send("GET /v1/join HTTP/1.1\r\n");
		$this->connection->advance(100.1, $this->handler);

		self::assertNotNull($this->client);
		fclose($this->client);
		$this->connection->advance(100.2, $this->handler);

		self::assertTrue($this->connection->isDone());
	}

	/**
	 * A negotiation nobody is left to hear the answer of would otherwise hold a peer connection open until it timed out.
	 */
	public function testClosingBeforeTheAnswerIsDeliveredFailsTheNegotiation() : void{
		$this->handler = new JoinHandler($negotiator = new FakeNegotiator(null), new AddressBlockTracker(), null, null, new RecordingLogger());
		$this->send("POST /v1/join/1234 HTTP/1.1\r\nHost: h\r\nContent-Type: application/sdp\r\nContent-Length: 5\r\n\r\noffer");
		$this->connection->advance(100.1, $this->handler);

		$this->connection->close();

		self::assertTrue($negotiator->negotiation?->isFailed());
	}

	public function testResponseTheClientNeverTakesIsGivenUpOn() : void{
		//more than a socket buffer holds, to a client that reads none of it
		$this->handler = new JoinHandler(new FakeNegotiator(str_repeat("a", 4 * 1024 * 1024)), new AddressBlockTracker(), null, null, new RecordingLogger());
		$this->send("POST /v1/join/1234 HTTP/1.1\r\nHost: h\r\nContent-Type: application/sdp\r\nContent-Length: 5\r\n\r\noffer");

		$this->connection->advance(100.0, $this->handler);
		$this->connection->advance(100.1, $this->handler);
		self::assertFalse($this->connection->isDone());

		$this->connection->advance(100.1 + HttpConnection::WRITE_TIMEOUT, $this->handler);
		self::assertFalse($this->connection->isDone());

		$this->connection->advance(100.1 + HttpConnection::WRITE_TIMEOUT + 0.01, $this->handler);
		self::assertTrue($this->connection->isDone());
	}
}

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

namespace pocketmine\nethernet\discovery;

use PHPUnit\Framework\TestCase;
use pocketmine\nethernet\discovery\packet\MessagePacket;
use pocketmine\nethernet\discovery\packet\PacketSerializer;
use pocketmine\nethernet\discovery\packet\RequestPacket;
use pocketmine\nethernet\discovery\packet\ResponsePacket;
use pocketmine\nethernet\FakeNegotiator;
use pocketmine\nethernet\negotiation\CandidateMode;
use pocketmine\nethernet\negotiation\ErrorCode;
use pocketmine\nethernet\negotiation\Negotiator;
use function random_int;
use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_getsockname;
use function socket_last_error;
use function socket_recvfrom;
use function socket_sendto;
use function socket_set_nonblock;
use function strlen;
use function usleep;
use const AF_INET;
use const SOCK_DGRAM;
use const SOCKET_EWOULDBLOCK;
use const SOL_UDP;

/**
 * Drives the transport over a real loopback socket.
 *
 * The parts worth testing here are the ones a unit test cannot reach: that the
 * socket is set up so datagrams actually arrive, and that a peer is answered at
 * the address its offer came from rather than one another peer can claim.
 */
final class LanSignalingTest extends TestCase{

	private const HOST_NETWORK_ID = 4242;
	private const PEER_NETWORK_ID = 9001;

	private ?LanSignaling $signaling = null;
	private ?\Socket $peer = null;
	private int $hostPort = 0;

	protected function setUp() : void{
		$peer = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		self::assertNotFalse($peer);
		self::assertTrue(socket_bind($peer, "127.0.0.1", 0));
		socket_set_nonblock($peer);
		$this->peer = $peer;
	}

	protected function tearDown() : void{
		$this->signaling?->shutdown();
		if($this->peer !== null){
			socket_close($this->peer);
		}
	}

	private function peer() : \Socket{
		$peer = $this->peer;
		self::assertNotNull($peer);

		return $peer;
	}

	private function startHost(Negotiator $negotiator) : void{
		//an ephemeral port keeps this off 7551, where a real client or another run
		//of the suite may already be listening
		for($attempt = 0; $attempt < 20; ++$attempt){
			$port = random_int(20000, 60000);
			$signaling = new LanSignaling(
				$negotiator,
				new FixedServerDataProvider(new ServerData(serverName: "test host", levelName: "test world", playerCount: 3, maxPlayerCount: 10)),
				self::HOST_NETWORK_ID,
				"127.0.0.1",
				$port
			);

			try{
				$signaling->start();
			}catch(\pocketmine\nethernet\signaling\SignalingException){
				continue;
			}

			$this->signaling = $signaling;
			$this->hostPort = $port;

			return;
		}

		self::fail("Could not find a free port to listen on");
	}

	private function sendToHost(packet\Packet $packet, int $senderId = self::PEER_NETWORK_ID) : void{
		$frame = PacketSerializer::encode($packet, $senderId);
		self::assertNotFalse(socket_sendto($this->peer(), $frame, strlen($frame), 0, "127.0.0.1", $this->hostPort));
	}

	/**
	 * @return array{packet\Packet, int}|null
	 */
	private function receive() : ?array{
		//one pass through the host, then look for what it sent back
		for($attempt = 0; $attempt < 50; ++$attempt){
			$this->signaling?->tick();

			$buffer = "";
			$from = "";
			$fromPort = 0;
			if(socket_recvfrom($this->peer(), $buffer, 65535, 0, $from, $fromPort) !== false){
				return PacketSerializer::decode($buffer);
			}
			if(socket_last_error($this->peer()) !== SOCKET_EWOULDBLOCK){
				break;
			}

			usleep(2000);
		}

		return null;
	}

	public function testRequestIsAnsweredWithTheHostAdvert() : void{
		$this->startHost(new FakeNegotiator("answer-sdp"));
		$this->sendToHost(new RequestPacket());

		$received = $this->receive();
		self::assertNotNull($received, "the host never answered the discovery request");

		[$packet, $senderId] = $received;
		self::assertInstanceOf(ResponsePacket::class, $packet);
		self::assertSame(self::HOST_NETWORK_ID, $senderId);

		$advert = ServerData::read($packet->applicationData);
		self::assertSame("test host", $advert->serverName);
		self::assertSame("test world", $advert->levelName);
		self::assertSame(3, $advert->playerCount);
	}

	/**
	 * A datagram this host broadcast itself comes straight back on the same socket.
	 * Acting on it would have the host answering its own request forever.
	 */
	public function testOwnDatagramIsIgnored() : void{
		$this->startHost(new FakeNegotiator("answer-sdp"));
		$this->sendToHost(new RequestPacket(), senderId: self::HOST_NETWORK_ID);

		self::assertNull($this->receive(), "the host answered a datagram carrying its own network id");
	}

	/** Traffic for another peer shares the broadcast domain and is not ours to answer. */
	public function testMessageForAnotherRecipientIsIgnored() : void{
		$this->startHost(new FakeNegotiator("answer-sdp"));
		$this->sendToHost(new MessagePacket(999999, "CONNECTREQUEST 1 offer-sdp"));

		self::assertNull($this->receive());
	}

	public function testOfferIsAnsweredAndCandidatesFollow() : void{
		$negotiator = new FakeNegotiator("answer-sdp", ["candidate:1 1 udp 1 127.0.0.1 1 typ host"]);
		$this->startHost($negotiator);

		$this->sendToHost(new MessagePacket(self::HOST_NETWORK_ID, "CONNECTREQUEST 77 offer-sdp"));

		$received = $this->receive();
		self::assertNotNull($received, "the host never answered the offer");
		[$packet] = $received;
		self::assertInstanceOf(MessagePacket::class, $packet);
		self::assertSame(self::PEER_NETWORK_ID, $packet->recipientId);

		$answer = Signal::parse($packet->data);
		self::assertSame(SignalType::CONNECT_RESPONSE, $answer->type);
		self::assertSame("77", $answer->connectionId);
		self::assertSame("answer-sdp", $answer->data);
		self::assertSame("offer-sdp", $negotiator->lastOffer);
		//the peer's own id, as an unsigned decimal string
		self::assertSame((string) self::PEER_NETWORK_ID, $negotiator->lastNetworkId);
		self::assertSame(CandidateMode::TRICKLE, $negotiator->lastMode);

		$received = $this->receive();
		self::assertNotNull($received, "the host never trickled its candidate");
		[$candidatePacket] = $received;
		self::assertInstanceOf(MessagePacket::class, $candidatePacket);

		$candidate = Signal::parse($candidatePacket->data);
		self::assertSame(SignalType::CANDIDATE_ADD, $candidate->type);
		self::assertSame("77", $candidate->connectionId);
		self::assertSame("candidate:1 1 udp 1 127.0.0.1 1 typ host", $candidate->data);
	}

	public function testPeerCandidateReachesTheNegotiation() : void{
		$negotiator = new FakeNegotiator("answer-sdp");
		$this->startHost($negotiator);

		$this->sendToHost(new MessagePacket(self::HOST_NETWORK_ID, "CONNECTREQUEST 77 offer-sdp"));
		self::assertNotNull($this->receive());

		$this->sendToHost(new MessagePacket(self::HOST_NETWORK_ID, "CANDIDATEADD 77 candidate:9 1 udp 1 10.0.0.1 5 typ host"));
		$this->receive();

		self::assertSame(["candidate:9 1 udp 1 10.0.0.1 5 typ host"], $negotiator->negotiation?->remoteCandidates);
	}

	/** Vanilla sends these as filler, and there is nothing to do with one. */
	public function testPingMessageIsIgnored() : void{
		$this->startHost(new FakeNegotiator("answer-sdp"));
		$this->sendToHost(new MessagePacket(self::HOST_NETWORK_ID, "Ping"));

		self::assertNull($this->receive());
	}

	public function testFailedNegotiationIsReportedToThePeer() : void{
		//failed from the moment it is created, since the offer has not even been
		//read by the time this test can reach in and fail one by hand
		$negotiator = new FakeNegotiator(null, failWith: ErrorCode::IDENTITY_NOT_ALLOWED);
		$this->startHost($negotiator);

		$this->sendToHost(new MessagePacket(self::HOST_NETWORK_ID, "CONNECTREQUEST 5 offer-sdp"));

		$received = $this->receive();
		self::assertNotNull($received);
		[$packet] = $received;
		self::assertInstanceOf(MessagePacket::class, $packet);

		$signal = Signal::parse($packet->data);
		self::assertSame(SignalType::CONNECT_ERROR, $signal->type);
		self::assertSame((string) ErrorCode::IDENTITY_NOT_ALLOWED->value, $signal->data);
	}

	/**
	 * Nothing stops a LAN peer from putting someone else's NetworkID in a datagram,
	 * so the answer has to go back to where the offer came from and nowhere else.
	 */
	public function testSpoofedSenderCannotRedirectTheAnswer() : void{
		$this->startHost(new FakeNegotiator("answer-sdp"));

		$impostor = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		self::assertNotFalse($impostor);
		self::assertTrue(socket_bind($impostor, "127.0.0.1", 0));
		socket_set_nonblock($impostor);

		try{
			$this->sendToHost(new MessagePacket(self::HOST_NETWORK_ID, "CONNECTREQUEST 77 offer-sdp"));

			//claims the peer's id from a different socket, before the host has answered
			$spoofed = PacketSerializer::encode(new MessagePacket(self::HOST_NETWORK_ID, "Ping"), self::PEER_NETWORK_ID);
			self::assertNotFalse(socket_sendto($impostor, $spoofed, strlen($spoofed), 0, "127.0.0.1", $this->hostPort));

			$received = $this->receive();
			self::assertNotNull($received, "the host never answered the offer");
			[$packet] = $received;
			self::assertInstanceOf(MessagePacket::class, $packet);
			self::assertSame(SignalType::CONNECT_RESPONSE, Signal::parse($packet->data)->type);

			$buffer = "";
			$from = "";
			$fromPort = 0;
			self::assertFalse(
				socket_recvfrom($impostor, $buffer, 65535, 0, $from, $fromPort),
				"the answer was redirected to the peer that claimed the network id"
			);
		}finally{
			socket_close($impostor);
		}
	}

	public function testBoundSocketReportsTheExpectedAddress() : void{
		$this->startHost(new FakeNegotiator("answer-sdp"));

		$address = "";
		$port = 0;
		self::assertTrue(socket_getsockname($this->peer(), $address, $port));
		self::assertSame("127.0.0.1", $address);
	}
}

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

namespace pocketmine\nethernet\server;

use PHPUnit\Framework\TestCase;
use pocketmine\nethernet\FakeMessageChannel;
use pocketmine\nethernet\FakePeerTransport;
use pocketmine\nethernet\negotiation\EstablishedPeer;
use pocketmine\nethernet\protocol\DisconnectReason;
use pocketmine\nethernet\protocol\Reliability;
use pocketmine\nethernet\RecordingEventListener;
use pocketmine\nethernet\RecordingLogger;
use pocketmine\nethernet\transport\ChannelPair;
use pocketmine\nethernet\transport\ConnectionBudgetConfiguration;
use pocketmine\nethernet\transport\TransportState;
use pocketmine\nethernet\utils\InternetAddress;
use function array_column;
use function array_fill;

final class SessionManagerTest extends TestCase{

	private RecordingEventListener $listener;
	private SessionManager $manager;

	protected function setUp() : void{
		$this->listener = new RecordingEventListener();
		$this->manager = new SessionManager($this->listener, new ConnectionBudgetConfiguration(), new RecordingLogger());
	}

	/**
	 * @phpstan-return array{EstablishedPeer, FakePeerTransport, FakeMessageChannel, FakeMessageChannel}
	 */
	private static function peer(string $networkId = "1234") : array{
		$transport = new FakePeerTransport();
		$transport->state = TransportState::CONNECTED;
		$reliable = new FakeMessageChannel(Reliability::RELIABLE);
		$unreliable = new FakeMessageChannel(Reliability::UNRELIABLE);

		return [
			new EstablishedPeer($transport, new ChannelPair($reliable, $unreliable), $networkId, new InternetAddress("192.0.2.10", 5000, 4), null),
			$transport,
			$reliable,
			$unreliable
		];
	}

	public function testOpenedSessionIsAnnouncedAndTracked() : void{
		[$first] = self::peer("1111");
		[$second] = self::peer("2222");

		$a = $this->manager->open($first);
		$b = $this->manager->open($second);

		self::assertNotSame($a->getId(), $b->getId());
		self::assertCount(2, $this->listener->opened);
		[$id, $networkId, $address, $identity] = $this->listener->opened[0];
		self::assertSame($a->getId(), $id);
		self::assertSame("1111", $networkId);
		self::assertSame("192.0.2.10:5000", $address->toString());
		self::assertNull($identity);
		self::assertSame(2, $this->manager->count());
		self::assertSame($b, $this->manager->getSession($b->getId()));
	}

	public function testIncomingPacketsAreDeliveredInOrder() : void{
		[$peer, , $reliable, $unreliable] = self::peer();
		$id = $this->manager->open($peer)->getId();
		$reliable->incoming = ["\x00one", "\x00two"];
		$unreliable->incoming = ["\x00three"];

		$this->manager->tick(1.0);

		self::assertSame([
			[$id, "one", Reliability::RELIABLE],
			[$id, "two", Reliability::RELIABLE],
			[$id, "three", Reliability::UNRELIABLE]
		], $this->listener->packets);
	}

	/**
	 * One busy session must not be able to hold the tick for as long as it has data.
	 */
	public function testDeliveriesPerTickAreCapped() : void{
		[$peer, , $reliable] = self::peer();
		$this->manager->open($peer);
		$this->manager->setPacketsPerTickLimit(8);
		$reliable->incoming = array_fill(0, 11, "\x00x");

		$this->manager->tick(1.0);
		self::assertCount(8, $this->listener->packets);

		$this->manager->tick(1.1);
		self::assertCount(11, $this->listener->packets);
	}

	public function testPacketsPerTickLimitMustBePositive() : void{
		$this->expectException(\InvalidArgumentException::class);
		$this->manager->setPacketsPerTickLimit(0);
	}

	public function testNothingIsReadWhileTheListenerCannotTakeMore() : void{
		[$peer, , $reliable] = self::peer();
		$this->manager->open($peer);
		$reliable->incoming = ["\x00one"];
		$this->listener->accepting = false;

		$this->manager->tick(1.0);
		self::assertSame([], $this->listener->packets);
		self::assertCount(1, $reliable->incoming, "the packet was taken off the transport with nowhere to go");

		$this->listener->accepting = true;
		$this->manager->tick(1.1);
		self::assertCount(1, $this->listener->packets);
	}

	public function testSessionThatDiedIsRemovedAndReportedOnce() : void{
		[$peer, $transport] = self::peer();
		$id = $this->manager->open($peer)->getId();
		$transport->state = TransportState::FAILED;

		$this->manager->tick(1.0);
		$this->manager->tick(1.1);

		self::assertSame([[$id, DisconnectReason::CONNECTION_FAILED]], $this->listener->closed);
		self::assertSame(0, $this->manager->count());
		self::assertNull($this->manager->getSession($id));
	}

	/**
	 * Once the host has asked for a session to end it has usually already dropped its own state for it, so a
	 * packet delivered afterwards arrives for a player that no longer exists.
	 */
	public function testNoPacketsAreDeliveredOnceADisconnectIsPending() : void{
		[$peer, , $reliable] = self::peer();
		$session = $this->manager->open($peer);
		$reliable->incoming = ["\x00late"];
		$reliable->buffered = 100;

		$session->initiateDisconnect(DisconnectReason::SERVER_DISCONNECT);
		$this->manager->tick(1.0);

		self::assertSame([], $this->listener->packets);
		self::assertSame([], $this->listener->closed, "the session closed before its queue had flushed");

		$reliable->buffered = 0;
		$this->manager->tick(1.1);
		self::assertSame([[$session->getId(), DisconnectReason::SERVER_DISCONNECT]], $this->listener->closed);
	}

	public function testDeliveryStopsWhenTheListenerDisconnectsTheSessionMidBatch() : void{
		[$peer, , $reliable] = self::peer();
		$session = $this->manager->open($peer);
		$reliable->incoming = ["\x00first", "\x00second", "\x00third"];
		$this->listener->onPacket = static function(int $sessionId, string $payload) use ($session) : void{
			if($payload === "first"){
				$session->initiateDisconnect(DisconnectReason::REJECTED_BY_HOST);
			}
		};

		$this->manager->tick(1.0);

		self::assertSame(["first"], array_column($this->listener->packets, 1));

		$this->manager->tick(1.1);
		self::assertSame([[$session->getId(), DisconnectReason::REJECTED_BY_HOST]], $this->listener->closed);
	}

	public function testUnusableDataEndsTheSessionWithThatReason() : void{
		[$peer, , , $unreliable] = self::peer();
		$id = $this->manager->open($peer)->getId();
		$unreliable->incoming = ["\x01fragment"];

		$this->manager->tick(1.0);

		self::assertSame([[$id, DisconnectReason::BAD_DATA]], $this->listener->closed);
	}

	/**
	 * A peer may accept smaller messages than this server sends by default; what it is sent has to fit.
	 */
	public function testSegmentsAreSizedForWhatThePeerAccepts() : void{
		[$peer, , $reliable] = self::peer();
		$reliable->maxMessageSize = 5;
		$session = $this->manager->open($peer);

		$session->send("abcdefgh");

		self::assertSame(["\x01abcd", "\x00efgh"], $reliable->sent);
	}

	/**
	 * A receipt is owed when the packet has left the send queue, and a packet has only left once everything
	 * queued before it has too.
	 */
	public function testReceiptsFollowTheSendQueueDraining() : void{
		[$peer, , $reliable] = self::peer();
		$session = $this->manager->open($peer);
		$id = $session->getId();

		$session->send("aaaa", receiptId: 10);
		$session->send("bbbb");
		$session->send("cccc", receiptId: 11);
		//each packet is five bytes on the wire; nothing has left yet
		$reliable->buffered = 15;

		$this->manager->tick(1.0);
		self::assertSame([], $this->listener->acks);

		$reliable->buffered = 9;
		$this->manager->tick(1.1);
		self::assertSame([[$id, 10]], $this->listener->acks);

		$reliable->buffered = 1;
		$this->manager->tick(1.2);
		self::assertSame([[$id, 10]], $this->listener->acks, "a packet with a byte still queued was acknowledged");

		$reliable->buffered = 0;
		$this->manager->tick(1.3);
		self::assertSame([[$id, 10], [$id, 11]], $this->listener->acks);
	}

	public function testReceiptsStillArriveWhileADisconnectFlushes() : void{
		[$peer, , $reliable] = self::peer();
		$session = $this->manager->open($peer);
		$session->send("bye", receiptId: 5);
		$reliable->buffered = 4;
		$session->initiateDisconnect(DisconnectReason::SERVER_DISCONNECT);

		$this->manager->tick(1.0);
		self::assertSame([], $this->listener->acks);

		$reliable->buffered = 0;
		$this->manager->tick(1.1);

		self::assertSame([[$session->getId(), 5]], $this->listener->acks);
		self::assertSame([[$session->getId(), DisconnectReason::SERVER_DISCONNECT]], $this->listener->closed);
	}

	public function testOutstandingReceiptsAreNotReportedForASessionThatDied() : void{
		[$peer, $transport, $reliable] = self::peer();
		$session = $this->manager->open($peer);
		$session->send("lost", receiptId: 5);
		$reliable->buffered = 5;
		$transport->state = TransportState::FAILED;

		$this->manager->tick(1.0);

		self::assertSame([], $this->listener->acks);
	}

	public function testBandwidthIsReportedOncePerInterval() : void{
		[$first, $firstTransport] = self::peer();
		[$second, $secondTransport] = self::peer();
		$this->manager->open($first);
		$this->manager->open($second);

		$firstTransport->bytesSent = 100;
		$secondTransport->bytesReceived = 40;
		$this->manager->tick(10.0);
		$firstTransport->bytesSent = 150;
		$this->manager->tick(10.0 + SessionManager::STATS_INTERVAL - 0.1);
		self::assertSame([], $this->listener->bandwidth);

		$this->manager->tick(10.0 + SessionManager::STATS_INTERVAL);
		self::assertSame([[150, 40]], $this->listener->bandwidth);

		$secondTransport->bytesSent = 7;
		$this->manager->tick(10.0 + 2 * SessionManager::STATS_INTERVAL);
		self::assertSame([[150, 40], [7, 0]], $this->listener->bandwidth, "bytes already reported were counted again");

		$this->manager->tick(10.0 + 3 * SessionManager::STATS_INTERVAL);
		self::assertCount(2, $this->listener->bandwidth, "an interval with no traffic was reported");
	}

	/**
	 * What a session exchanged just before it ended is part of the total too.
	 */
	public function testBytesOfASessionThatEndedAreStillCounted() : void{
		[$peer, $transport] = self::peer();
		$this->manager->open($peer);
		$this->manager->tick(10.0);

		$transport->bytesSent = 30;
		$transport->state = TransportState::FAILED;
		$this->manager->tick(10.1);
		$this->manager->tick(10.0 + SessionManager::STATS_INTERVAL);

		self::assertSame([[30, 0]], $this->listener->bandwidth);
	}

	public function testPingIsReportedWhenItChanges() : void{
		[$peer, $transport] = self::peer();
		$id = $this->manager->open($peer)->getId();
		$this->manager->tick(10.0);

		$this->manager->tick(11.0);
		self::assertSame([], $this->listener->pings, "a ping was reported before one had been measured");

		$transport->roundTripTime = 35;
		$this->manager->tick(12.0);
		$this->manager->tick(13.0);
		self::assertSame([[$id, 35]], $this->listener->pings);

		$transport->roundTripTime = 50;
		$this->manager->tick(14.0);
		self::assertSame([[$id, 35], [$id, 50]], $this->listener->pings);
	}

	public function testShutdownClosesEverySession() : void{
		[$first, $firstTransport] = self::peer();
		[$second, $secondTransport] = self::peer();
		$a = $this->manager->open($first)->getId();
		$b = $this->manager->open($second)->getId();

		$this->manager->shutdown();

		self::assertSame([
			[$a, DisconnectReason::SERVER_SHUTDOWN],
			[$b, DisconnectReason::SERVER_SHUTDOWN]
		], $this->listener->closed);
		self::assertTrue($firstTransport->closed);
		self::assertTrue($secondTransport->closed);
		self::assertSame(0, $this->manager->count());
	}

	/**
	 * A listener that failed to take a session on never knew about it, so it is not told the session ended.
	 */
	public function testSessionTheListenerFailsToOpenIsClosedWithoutFurtherEvents() : void{
		[$peer, $transport] = self::peer();
		$this->listener->failOpenWith = new \RuntimeException("host refused the session");

		try{
			$this->manager->open($peer);
			self::fail("the listener's failure was swallowed");
		}catch(\RuntimeException $e){
			self::assertSame("host refused the session", $e->getMessage());
		}

		self::assertTrue($transport->closed);
		self::assertSame(0, $this->manager->count());
		self::assertSame([], $this->listener->closed);
	}
}

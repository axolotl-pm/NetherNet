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

namespace pocketmine\nethernet\session;

use PHPUnit\Framework\TestCase;
use pocketmine\nethernet\FakeMessageChannel;
use pocketmine\nethernet\FakePeerTransport;
use pocketmine\nethernet\protocol\DisconnectReason;
use pocketmine\nethernet\protocol\framing\Segmenter;
use pocketmine\nethernet\protocol\Reliability;
use pocketmine\nethernet\transport\ChannelPair;
use pocketmine\nethernet\transport\ConnectionBudgetConfiguration;
use pocketmine\nethernet\transport\TransportState;
use pocketmine\nethernet\utils\InternetAddress;
use function str_repeat;

final class SessionTest extends TestCase{

	private FakePeerTransport $transport;
	private FakeMessageChannel $reliable;
	private FakeMessageChannel $unreliable;

	private function open(int $segmentPayloadSize = Segmenter::MAX_SEGMENT_PAYLOAD_SIZE, ?ConnectionBudgetConfiguration $budget = null) : Session{
		$this->transport = new FakePeerTransport();
		$this->transport->state = TransportState::CONNECTED;
		$this->reliable = new FakeMessageChannel(Reliability::RELIABLE);
		$this->unreliable = new FakeMessageChannel(Reliability::UNRELIABLE);

		return new Session(
			7,
			$this->transport,
			new ChannelPair($this->reliable, $this->unreliable),
			new InternetAddress("192.0.2.10", 5000, 4),
			null,
			new Segmenter($segmentPayloadSize),
			$budget ?? new ConnectionBudgetConfiguration()
		);
	}

	public function testPacketIsFramedOntoTheChosenChannel() : void{
		$session = $this->open();

		$session->send("hello");
		$session->send("fast", Reliability::UNRELIABLE);

		self::assertSame(["\x00hello"], $this->reliable->sent);
		self::assertSame(["\x00fast"], $this->unreliable->sent);
	}

	/**
	 * The unreliable channel may lose any one message, so a packet spread over several could never be trusted
	 * to arrive whole. That is the caller's mistake, and it must not cost them the session.
	 */
	public function testUnreliableChannelRefusesPacketsThatNeedSplitting() : void{
		$session = $this->open(segmentPayloadSize: 4);

		try{
			$session->send("abcdefghij", Reliability::UNRELIABLE);
			self::fail("an oversized unreliable packet was accepted");
		}catch(\InvalidArgumentException){
			self::assertSame([], $this->unreliable->sent);
			self::assertTrue($session->isConnected());
		}
	}

	public function testFragmentsAreReassembledIntoOnePacket() : void{
		$session = $this->open();
		$this->reliable->incoming = ["\x01abc", "\x00def", "\x00next"];

		$first = $session->receive();
		self::assertNotNull($first);
		self::assertSame("abcdef", $first->payload);
		self::assertSame(Reliability::RELIABLE, $first->reliability);

		self::assertSame("next", $session->receive()?->payload);
		self::assertNull($session->receive());
	}

	public function testRefusedSendClosesTheSession() : void{
		$session = $this->open();
		$this->reliable->failSends = true;

		try{
			$session->send("hello");
			self::fail("the send was reported as successful");
		}catch(SessionException){
			self::assertTrue($session->isFullyDisconnected());
			self::assertSame(DisconnectReason::SEND_FAILED, $session->getDisconnectReason());
			self::assertTrue($this->transport->closed);
		}
	}

	public function testPendingDisconnectWaitsForQueuedDataToLeave() : void{
		$session = $this->open();
		$this->reliable->buffered = 100;

		$session->initiateDisconnect(DisconnectReason::SERVER_DISCONNECT);
		$session->update(50.0);
		self::assertFalse($session->isConnected());
		self::assertFalse($session->isFullyDisconnected());
		self::assertFalse($this->transport->closed);

		$this->reliable->buffered = 0;
		$session->update(50.1);
		self::assertTrue($session->isFullyDisconnected());
		self::assertSame(DisconnectReason::SERVER_DISCONNECT, $session->getDisconnectReason());
		self::assertTrue($this->transport->closed);
	}

	public function testPendingDisconnectGivesUpAfterTheFlushTimeout() : void{
		$session = $this->open();
		$this->reliable->buffered = 100;

		$session->initiateDisconnect(DisconnectReason::SERVER_SHUTDOWN);
		$session->update(50.0);
		$session->update(50.0 + Session::DISCONNECT_FLUSH_TIMEOUT - 0.01);
		self::assertFalse($session->isConnected());
		self::assertFalse($session->isFullyDisconnected());

		$session->update(50.0 + Session::DISCONNECT_FLUSH_TIMEOUT);
		self::assertTrue($session->isFullyDisconnected());
		self::assertSame(DisconnectReason::SERVER_SHUTDOWN, $session->getDisconnectReason());
	}

	public function testPacketsSentAfterADisconnectWasInitiatedAreDropped() : void{
		$session = $this->open();
		$session->send("goodbye");
		$session->initiateDisconnect(DisconnectReason::SERVER_DISCONNECT);

		$session->send("too late");

		self::assertSame(["\x00goodbye"], $this->reliable->sent);
	}

	public function testFirstDisconnectReasonIsTheOneKept() : void{
		$session = $this->open();

		$session->forciblyDisconnect(DisconnectReason::BAD_DATA);
		$session->forciblyDisconnect(DisconnectReason::SERVER_SHUTDOWN);
		$session->initiateDisconnect(DisconnectReason::ADDRESS_BLOCKED);

		self::assertSame(DisconnectReason::BAD_DATA, $session->getDisconnectReason());
	}

	public function testUnreadBytesOverBudgetCloseTheSession() : void{
		$session = $this->open(budget: new ConnectionBudgetConfiguration(maxMessageSize: 16, maxPayloadSize: 64, maxReceiveQueueSize: 32, maxSendQueueSize: 32));
		$this->reliable->incoming = [str_repeat("x", 20)];
		$this->unreliable->incoming = [str_repeat("x", 13)];

		$session->update(1.0);

		self::assertSame(DisconnectReason::RECEIVE_QUEUE_TOO_MANY_BYTES, $session->getDisconnectReason());
	}

	public function testUnreadMessagesOverBudgetCloseTheSession() : void{
		$session = $this->open(budget: new ConnectionBudgetConfiguration(maxReceiveQueueMessages: 2));
		$this->reliable->incoming = ["\x00a", "\x00b", "\x00c"];

		$session->update(1.0);

		self::assertSame(DisconnectReason::RECEIVE_QUEUE_TOO_MANY_MESSAGES, $session->getDisconnectReason());
	}

	public function testUnsentBytesOverBudgetCloseTheSession() : void{
		$session = $this->open(budget: new ConnectionBudgetConfiguration(maxMessageSize: 16, maxPayloadSize: 64, maxReceiveQueueSize: 32, maxSendQueueSize: 32));
		$this->reliable->buffered = 20;
		$this->unreliable->buffered = 13;

		$session->update(1.0);

		self::assertSame(DisconnectReason::SEND_QUEUE_TOO_MANY_BYTES, $session->getDisconnectReason());
	}

	public function testFailedTransportIsReportedAsAConnectionFailure() : void{
		$session = $this->open();
		$this->transport->state = TransportState::FAILED;

		$session->update(1.0);

		self::assertSame(DisconnectReason::CONNECTION_FAILED, $session->getDisconnectReason());
	}

	/**
	 * A transport that polls straight from connected to closed still remembers whether connectivity was lost
	 * on the way, and that is the only thing telling a timeout apart from the peer leaving.
	 */
	public function testClosedTransportThatLostConnectivityIsReportedAsAConnectionFailure() : void{
		$session = $this->open();
		$this->transport->state = TransportState::CLOSED;
		$this->transport->lostConnectivity = true;

		$session->update(1.0);

		self::assertSame(DisconnectReason::CONNECTION_FAILED, $session->getDisconnectReason());
	}

	public function testOrderlyClosedTransportIsReportedAsThePeerLeaving() : void{
		$session = $this->open();
		$this->transport->state = TransportState::CLOSED;

		$session->update(1.0);

		self::assertSame(DisconnectReason::PEER_DISCONNECT, $session->getDisconnectReason());
	}

	public function testChannelClosedByThePeerEndsTheSession() : void{
		$session = $this->open();
		$this->unreliable->closed = true;

		$session->update(1.0);

		self::assertSame(DisconnectReason::PEER_DISCONNECT, $session->getDisconnectReason());
		self::assertTrue($this->reliable->closed, "the surviving channel was left open");
	}
}

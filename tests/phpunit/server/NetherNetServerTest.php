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
use pocketmine\nethernet\FakeNegotiator;
use pocketmine\nethernet\FakePeerTransport;
use pocketmine\nethernet\FakeSignaling;
use pocketmine\nethernet\negotiation\EstablishedPeer;
use pocketmine\nethernet\protocol\DisconnectReason;
use pocketmine\nethernet\protocol\Reliability;
use pocketmine\nethernet\RecordingEventListener;
use pocketmine\nethernet\RecordingLogger;
use pocketmine\nethernet\signaling\SignalingException;
use pocketmine\nethernet\transport\ChannelPair;
use pocketmine\nethernet\transport\ConnectionBudgetConfiguration;
use pocketmine\nethernet\transport\TransportState;
use pocketmine\nethernet\utils\InternetAddress;
use function str_repeat;

final class NetherNetServerTest extends TestCase{

	private FakeNegotiator $negotiator;
	private RecordingEventListener $listener;
	private NetherNetServer $server;

	protected function setUp() : void{
		$logger = new RecordingLogger();
		$this->negotiator = new FakeNegotiator();
		$this->listener = new RecordingEventListener();
		$this->server = new NetherNetServer(
			$this->negotiator,
			$this->listener,
			new ConnectionBudgetConfiguration(),
			$logger
		);
	}

	/**
	 * @phpstan-return array{EstablishedPeer, FakePeerTransport, FakeMessageChannel}
	 */
	private static function peer(string $ip = "192.0.2.10", int $version = 4, ?string $clientAddress = null) : array{
		$transport = new FakePeerTransport();
		$transport->state = TransportState::CONNECTED;
		$reliable = new FakeMessageChannel(Reliability::RELIABLE);

		return [
			new EstablishedPeer($transport, new ChannelPair($reliable, new FakeMessageChannel(Reliability::UNRELIABLE)), "1234", new InternetAddress($ip, 5000, $version), $clientAddress, null),
			$transport,
			$reliable
		];
	}

	public function testStartHandsEveryTransportTheServersCollaborators() : void{
		$this->server->addSignaling($first = new FakeSignaling());
		$this->server->addSignaling($second = new FakeSignaling());

		self::assertSame(ServerState::CREATED, $this->server->getState());
		$this->server->start();

		self::assertTrue($this->server->isRunning());
		self::assertSame($this->negotiator, $first->context?->negotiator);
		self::assertSame($first->context, $second->context);
	}

	/**
	 * A server that could not start everything is not half running: what did start is stopped again, and the
	 * server says so instead of claiming to be up.
	 */
	public function testFailedStartLeavesTheServerStopped() : void{
		$this->server->addSignaling($started = new FakeSignaling());
		$this->server->addSignaling(new FakeSignaling(failToStart: true));

		try{
			$this->server->start();
			self::fail("the server started without one of its transports");
		}catch(SignalingException){
			self::assertFalse($this->server->isRunning());
			self::assertSame(ServerState::STOPPED, $this->server->getState());
			self::assertSame(1, $started->shutdowns);
			self::assertTrue($this->negotiator->shutDown);
		}
	}

	public function testStartingTwiceIsRefused() : void{
		$this->server->start();

		$this->expectException(\LogicException::class);
		$this->server->start();
	}

	public function testTransportsCannotBeAddedOnceStarted() : void{
		$this->server->start();

		$this->expectException(\LogicException::class);
		$this->server->addSignaling(new FakeSignaling());
	}

	public function testNothingIsTickedBeforeTheServerStarts() : void{
		$this->server->addSignaling($signaling = new FakeSignaling());

		$this->server->tick(1.0);

		self::assertSame([], $signaling->ticks);
		self::assertSame([], $this->negotiator->ticks);
	}

	public function testEverythingInATickSeesTheSameTime() : void{
		$this->server->addSignaling($signaling = new FakeSignaling());
		$this->server->start();

		$this->server->tick(42.5);

		self::assertSame([42.5], $signaling->ticks);
		self::assertSame([42.5], $this->negotiator->ticks);
	}

	/**
	 * The offer may have arrived through a transport that never saw the peer's real address, or before the
	 * block was placed; the connection itself is the last place to catch it.
	 */
	public function testPeerConnectingFromABlockedAddressIsClosedInstead() : void{
		$this->server->start();
		$this->server->blockAddress("192.0.2.10", -1);
		[$peer, $transport] = self::peer();
		$this->negotiator->established[] = $peer;

		$this->server->tick(1.0);

		self::assertSame([], $this->listener->opened);
		self::assertTrue($transport->closed);
	}

	public function testBlockingAnAddressDisconnectsItsSessions() : void{
		$this->server->start();
		[$blocked] = self::peer("2001:db8::1", 6);
		[$other] = self::peer("192.0.2.99");
		$this->negotiator->established = [$blocked, $other];
		$this->server->tick(1.0);
		$blockedId = $this->listener->opened[0][0];

		//a different spelling of the same address
		$this->server->blockAddress("2001:db8:0:0:0:0:0:1");
		$this->server->tick(1.1);

		self::assertSame([[$blockedId, DisconnectReason::ADDRESS_BLOCKED]], $this->listener->closed);
	}

	public function testBlockingAnAddressDisconnectsTheSessionsSignaledFromIt() : void{
		$this->server->start();
		[$blocked] = self::peer(clientAddress: "203.0.113.7");
		[$other] = self::peer(clientAddress: "203.0.113.8");
		$this->negotiator->established = [$blocked, $other];
		$this->server->tick(1.0);
		$blockedId = $this->listener->opened[0][0];

		$this->server->blockAddress("203.0.113.7");
		$this->server->tick(1.1);

		self::assertSame([[$blockedId, DisconnectReason::ADDRESS_BLOCKED]], $this->listener->closed);
	}

	public function testGracefulShutdownStopsAcceptingAtOnceAndLetsSessionsFlush() : void{
		$this->server->addSignaling($signaling = new FakeSignaling());
		$this->server->start();
		[$peer, $transport, $reliable] = self::peer();
		$this->negotiator->established[] = $peer;
		$this->server->tick(1.0);
		$sessionId = $this->listener->opened[0][0];
		$reliable->buffered = 100;

		$this->server->initiateShutdown();

		self::assertSame(ServerState::STOPPING, $this->server->getState());
		self::assertFalse($this->server->isRunning());
		self::assertSame(1, $signaling->shutdowns);
		self::assertTrue($this->negotiator->shutDown);
		self::assertFalse($transport->closed, "the session was cut off with data still queued");

		$this->server->tick(1.1);
		self::assertSame(ServerState::STOPPING, $this->server->getState());
		self::assertSame([1.0], $signaling->ticks, "a transport was ticked after it had been shut down");

		$reliable->buffered = 0;
		$this->server->tick(1.2);

		self::assertSame([[$sessionId, DisconnectReason::SERVER_SHUTDOWN]], $this->listener->closed);
		self::assertSame(ServerState::STOPPED, $this->server->getState());
		self::assertSame(1, $signaling->shutdowns, "the transport was shut down a second time");
	}

	/**
	 * A host acts on what it last heard, and a session may have ended since; that is not the host's error.
	 */
	public function testCommandsForASessionThatIsGoneAreIgnored() : void{
		$this->server->start();

		$this->server->sendPacket(99, "hello");
		$this->server->closeSession(99);

		self::assertSame([], $this->listener->closed);
	}

	public function testSendTheTransportRefusesEndsTheSessionInsteadOfThrowing() : void{
		$this->server->start();
		[$peer, , $reliable] = self::peer();
		$this->negotiator->established[] = $peer;
		$this->server->tick(1.0);
		$sessionId = $this->listener->opened[0][0];
		$reliable->failSends = true;

		$this->server->sendPacket($sessionId, "hello");
		$this->server->tick(1.1);

		self::assertSame([[$sessionId, DisconnectReason::SEND_FAILED]], $this->listener->closed);
	}

	public function testReliablePacketTooLargeToSendClosesTheSession() : void{
		$this->server->start();
		[$peer, , $reliable] = self::peer();
		$reliable->maxMessageSize = 3;
		$this->negotiator->established[] = $peer;
		$this->server->tick(1.0);
		$sessionId = $this->listener->opened[0][0];

		$this->server->sendPacket($sessionId, str_repeat("x", 1024));
		$this->server->tick(1.1);

		self::assertSame([], $reliable->sent);
		self::assertSame([[$sessionId, DisconnectReason::SEND_FAILED]], $this->listener->closed);
	}

	public function testUnreliablePacketTooLargeToSendIsOnlyLost() : void{
		$this->server->start();
		[$peer, , $reliable] = self::peer();
		$reliable->maxMessageSize = 3;
		$this->negotiator->established[] = $peer;
		$this->server->tick(1.0);
		$sessionId = $this->listener->opened[0][0];

		$this->server->sendPacket($sessionId, "too long for one segment", Reliability::UNRELIABLE);
		$this->server->tick(1.1);

		self::assertSame([], $this->listener->closed);
	}

	public function testListenerCanTurnASessionAwayAsItOpens() : void{
		$this->server->start();
		[$peer, $transport, $reliable] = self::peer();
		$reliable->incoming = ["\x00hello"];
		$this->negotiator->established[] = $peer;
		$this->listener->onOpen = fn(int $sessionId) => $this->server->closeSession($sessionId, DisconnectReason::REJECTED_BY_HOST);

		$this->server->tick(1.0);

		self::assertCount(1, $this->listener->opened);
		self::assertSame([[$this->listener->opened[0][0], DisconnectReason::REJECTED_BY_HOST]], $this->listener->closed);
		self::assertSame([], $this->listener->packets);
		self::assertTrue($transport->closed);
	}

	/**
	 * A listener that throws ends the tick, and the peers it had not been shown yet would otherwise stay
	 * connected with nothing ever reading from them.
	 */
	public function testPeersNotYetOpenedAreClosedWhenTheListenerThrows() : void{
		$this->server->start();
		[$first, $firstTransport] = self::peer();
		[$second, $secondTransport] = self::peer("192.0.2.11");
		$this->negotiator->established = [$first, $second];
		$this->listener->failOpenWith = new \RuntimeException("host refused the session");

		try{
			$this->server->tick(1.0);
			self::fail("the listener's failure was swallowed");
		}catch(\RuntimeException){
			self::assertTrue($firstTransport->closed);
			self::assertTrue($secondTransport->closed);
		}
	}

	public function testGracefulShutdownWithNoSessionsStopsOnTheNextTick() : void{
		$this->server->start();

		$this->server->initiateShutdown();
		$this->server->tick(1.0);

		self::assertSame(ServerState::STOPPED, $this->server->getState());
	}

	public function testImmediateShutdownClosesSessionsWithoutWaiting() : void{
		$this->server->addSignaling($signaling = new FakeSignaling());
		$this->server->start();
		[$peer, $transport, $reliable] = self::peer();
		$this->negotiator->established[] = $peer;
		$this->server->tick(1.0);
		$reliable->buffered = 100;

		$this->server->shutdown();
		$this->server->shutdown();

		self::assertSame(ServerState::STOPPED, $this->server->getState());
		self::assertTrue($transport->closed);
		self::assertSame(DisconnectReason::SERVER_SHUTDOWN, $this->listener->closed[0][1] ?? null);
		self::assertSame(1, $signaling->shutdowns);
	}

	public function testShutdownCanCutAGracefulStopShort() : void{
		$this->server->start();
		[$peer, $transport, $reliable] = self::peer();
		$this->negotiator->established[] = $peer;
		$this->server->tick(1.0);
		$reliable->buffered = 100;

		$this->server->initiateShutdown();
		$this->server->shutdown();

		self::assertSame(ServerState::STOPPED, $this->server->getState());
		self::assertTrue($transport->closed);
	}
}

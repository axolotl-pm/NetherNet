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
use pocketmine\nethernet\FakeSignaling;
use pocketmine\nethernet\FakeTransportFactory;
use pocketmine\nethernet\identity\AssertionIdentityVerifier;
use pocketmine\nethernet\identity\SelfSignedIdentityProvider;
use pocketmine\nethernet\identity\ServerIdentity;
use pocketmine\nethernet\protocol\DisconnectReason;
use pocketmine\nethernet\protocol\Reliability;
use pocketmine\nethernet\RecordingEventListener;
use pocketmine\nethernet\RecordingLogger;
use pocketmine\nethernet\sdp\SessionDescription;
use pocketmine\nethernet\SdpFixture;
use pocketmine\nethernet\transport\TransportState;
use pocketmine\nethernet\utils\InternetAddress;

/**
 * Runs the server the way a host builds it, with only the transport faked: an offer goes in through a signaling
 * transport and comes out the other end as a session.
 */
final class ServerCreationTest extends TestCase{

	private const CLIENT_ADDRESS = "203.0.113.7";
	private const RELAY_ADDRESS = "192.0.2.10";

	private static ServerIdentity $identity;

	private FakeTransportFactory $factory;
	private RecordingEventListener $listener;
	private FakeSignaling $signaling;
	private NetherNetServer $server;

	public static function setUpBeforeClass() : void{
		self::$identity = ServerIdentity::generate();
	}

	protected function setUp() : void{
		$this->factory = new FakeTransportFactory();
		$this->listener = new RecordingEventListener();
		$this->server = NetherNetServer::create(self::configuration(transportFactory: $this->factory), $this->listener);
		$this->server->addSignaling($this->signaling = new FakeSignaling());
		$this->server->start();
	}

	/**
	 * @phpstan-param list<string>|null $advertisedAddresses
	 */
	private static function configuration(
		?FakeTransportFactory $transportFactory = null,
		float $gatheringTimeout = ServerConfiguration::DEFAULT_GATHERING_TIMEOUT,
		float $channelTimeout = ServerConfiguration::DEFAULT_CHANNEL_TIMEOUT,
		int $maxRemoteCandidates = 4,
		?array $advertisedAddresses = null
	) : ServerConfiguration{
		return new ServerConfiguration(
			identityProvider: new SelfSignedIdentityProvider(self::$identity),
			logger: new RecordingLogger(),
			identityVerifier: new AssertionIdentityVerifier(allowAnonymous: true),
			transportFactory: $transportFactory ?? new FakeTransportFactory(),
			gatheringTimeout: $gatheringTimeout,
			channelTimeout: $channelTimeout,
			maxRemoteCandidates: $maxRemoteCandidates,
			advertisedAddresses: $advertisedAddresses
		);
	}

	/**
	 * Takes an offer signaled from the client address all the way to an open session reached through the relay.
	 *
	 * @phpstan-return array{FakePeerTransport, FakeMessageChannel}
	 */
	private function connect(string $signaledFrom = self::CLIENT_ADDRESS) : array{
		$negotiator = $this->signaling->context?->negotiator;
		self::assertNotNull($negotiator);
		$negotiation = $negotiator->beginNegotiation(SdpFixture::description(), "1234", peerAddress: $signaledFrom);

		self::assertCount(1, $this->factory->created);
		$transport = $this->factory->created[0];
		$transport->gatheringComplete = true;
		$transport->localDescription = SdpFixture::description([SdpFixture::HOST_CANDIDATE]);
		$this->server->tick(100.0);

		$answer = $negotiation->getAnswer();
		self::assertNotNull($answer);
		self::assertNotNull(SessionDescription::parse($answer)->getIdentity());

		$reliable = new FakeMessageChannel(Reliability::RELIABLE);
		$transport->pendingChannels = [$reliable, new FakeMessageChannel(Reliability::UNRELIABLE)];
		$transport->remoteAddress = new InternetAddress(self::RELAY_ADDRESS, 5000, 4);
		$transport->state = TransportState::CONNECTED;
		$this->server->tick(100.1);

		return [$transport, $reliable];
	}

	public function testSignaledOfferBecomesASessionThatCarriesPackets() : void{
		[, $reliable] = $this->connect();

		self::assertCount(1, $this->listener->opened);
		[$sessionId, $networkId, $address, $clientAddress] = $this->listener->opened[0];
		self::assertSame("1234", $networkId);
		self::assertSame(self::RELAY_ADDRESS, $address->ip);
		self::assertSame(self::CLIENT_ADDRESS, $clientAddress);

		$reliable->incoming = ["\x00ping"];
		$this->server->tick(100.2);
		self::assertSame([[$sessionId, "ping", Reliability::RELIABLE]], $this->listener->packets);

		$this->server->sendPacket($sessionId, "pong");
		self::assertSame(["\x00pong"], $reliable->sent);
	}

	/**
	 * A proxy header or a plugin can spell one IPv6 address many ways; the host must only ever see one of them.
	 */
	public function testIpv6ClientAddressIsReportedCanonicallyHoweverItWasSpelled() : void{
		$this->connect("[2001:DB8:0:0:0:0:0:1]");
		[$sessionId, , , $clientAddress] = $this->listener->opened[0];
		self::assertSame("2001:db8::1", $clientAddress);

		$this->server->blockAddress("2001:0db8::0001");
		$this->server->tick(100.2);

		self::assertSame([[$sessionId, DisconnectReason::ADDRESS_BLOCKED]], $this->listener->closed);
	}

	public function testOfferSignaledFromSomethingThatIsNotAnAddressIsRefused() : void{
		$negotiator = $this->signaling->context?->negotiator;
		self::assertNotNull($negotiator);

		$this->expectException(\InvalidArgumentException::class);
		$negotiator->beginNegotiation(SdpFixture::description(), "1234", peerAddress: "example.com");
	}

	public function testClientBlockedWhileItNegotiatedNeverBecomesASession() : void{
		$negotiator = $this->signaling->context?->negotiator;
		self::assertNotNull($negotiator);
		$negotiator->beginNegotiation(SdpFixture::description(), "1234", peerAddress: self::CLIENT_ADDRESS);
		$transport = $this->factory->created[0];
		$transport->gatheringComplete = true;
		$transport->localDescription = SdpFixture::description([SdpFixture::HOST_CANDIDATE]);
		$this->server->tick(100.0);

		$this->server->blockAddress(self::CLIENT_ADDRESS, -1);
		$transport->pendingChannels = [new FakeMessageChannel(Reliability::RELIABLE), new FakeMessageChannel(Reliability::UNRELIABLE)];
		$transport->remoteAddress = new InternetAddress(self::RELAY_ADDRESS, 5000, 4);
		$transport->state = TransportState::CONNECTED;
		$this->server->tick(100.1);

		self::assertSame([], $this->listener->opened);
		self::assertTrue($transport->closed);
	}

	/**
	 * @phpstan-return iterable<string, array{\Closure() : ServerConfiguration}>
	 */
	public static function unusableConfigurations() : iterable{
		yield "gathering timeout" => [static fn() => self::configuration(gatheringTimeout: 0.0)];
		yield "channel timeout" => [static fn() => self::configuration(channelTimeout: -1.0)];
		yield "remote candidate limit" => [static fn() => self::configuration(maxRemoteCandidates: 0)];
		yield "advertised address" => [static fn() => self::configuration(advertisedAddresses: ["not-an-address"])];
	}

	/**
	 * @dataProvider unusableConfigurations
	 * @phpstan-param \Closure() : ServerConfiguration $create
	 */
	public function testConfigurationTheServerCouldNotRunWithIsRefused(\Closure $create) : void{
		$this->expectException(\InvalidArgumentException::class);
		$create();
	}
}

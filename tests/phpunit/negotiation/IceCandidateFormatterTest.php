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

namespace pocketmine\nethernet\negotiation;

use PHPUnit\Framework\TestCase;

final class IceCandidateFormatterTest extends TestCase{

	private const LAN_HOST = "1 1 UDP 2114977791 192.168.45.45 38922 typ host";
	private const DOCKER_HOST = "2 1 UDP 2114977535 172.17.0.1 38922 typ host";
	private const IPV6_HOST = "3 1 UDP 2116025599 fd7a:115c:a1e0::1 38922 typ host";

	/**
	 * Gathers candidates unreachable from the public internet; unadvertised candidates are dropped to avoid
	 * unnecessary connectivity checks.
	 */
	public function testCandidatesOnOtherAddressesAreDropped() : void{
		$advertised = IceCandidateFormatter::advertise([self::LAN_HOST, self::DOCKER_HOST, self::IPV6_HOST], ["192.168.45.45"]);

		self::assertSame([self::LAN_HOST], $advertised);
	}

	/**
	 * Behind a port-forwarding NAT, the external address must be announced using the port from the gathered UDP
	 * host candidate.
	 */
	public function testForeignAddressIsAnnouncedAsServerReflexiveOnTheGatheredPort() : void{
		$advertised = IceCandidateFormatter::advertise([self::LAN_HOST, self::DOCKER_HOST], ["203.0.113.10"]);

		self::assertSame(["advertised 1 udp " . ((100 << 24) + (65535 << 8) + 255) . " 203.0.113.10 38922 typ srflx raddr 0.0.0.0 rport 0"], $advertised);
	}

	public function testHeldAndForeignAddressesCanBeAdvertisedTogether() : void{
		$advertised = IceCandidateFormatter::advertise([self::LAN_HOST, self::DOCKER_HOST], ["192.168.45.45", "203.0.113.10"]);

		self::assertCount(2, $advertised);
		self::assertSame(self::LAN_HOST, $advertised[0]);
		self::assertStringContainsString("203.0.113.10 38922 typ srflx", $advertised[1]);
	}

	/**
	 * Without a UDP host candidate, no port is available for external addresses, so no synthetic candidates are generated.
	 */
	public function testForeignAddressNeedsAHostCandidateForItsPort() : void{
		self::assertSame([], IceCandidateFormatter::advertise([], ["203.0.113.10"]));
		self::assertSame([], IceCandidateFormatter::advertise(["1 1 TCP 2105458943 192.168.45.45 9 typ host tcptype active"], ["203.0.113.10"]));
	}
}

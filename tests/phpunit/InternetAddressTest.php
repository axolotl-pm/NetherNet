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

namespace pocketmine\nethernet;

use PHPUnit\Framework\TestCase;

final class InternetAddressTest extends TestCase{

	public function testParsesIpv4() : void{
		$address = InternetAddress::parse("192.0.2.10:54321");

		self::assertNotNull($address);
		self::assertSame("192.0.2.10", $address->ip);
		self::assertSame(54321, $address->port);
		self::assertSame(4, $address->version);
		self::assertSame("192.0.2.10:54321", $address->toString());
	}

	public function testParsesUnbracketedIpv6() : void{
		$address = InternetAddress::parse("2001:db8::1:5000");

		self::assertNotNull($address);
		self::assertSame("2001:db8::1", $address->ip);
		self::assertSame(5000, $address->port);
		self::assertSame(6, $address->version);
		self::assertSame("[2001:db8::1]:5000", $address->toString());
	}

	public function testParsesBracketedIpv6() : void{
		$address = InternetAddress::parse("[::1]:5000");

		self::assertNotNull($address);
		self::assertSame("::1", $address->ip);
		self::assertSame(5000, $address->port);
	}

	public function testRejectsMalformedAddresses() : void{
		self::assertNull(InternetAddress::parse("192.0.2.10"));
		self::assertNull(InternetAddress::parse("example.test:80"));
		self::assertNull(InternetAddress::parse("192.0.2.10:70000"));
		self::assertNull(InternetAddress::parse("192.0.2.10:"));
	}

	public function testRejectsInvalidVersion() : void{
		$this->expectException(\InvalidArgumentException::class);
		new InternetAddress("192.0.2.10", 80, 5);
	}
}

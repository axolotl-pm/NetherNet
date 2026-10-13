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

final class ReverseProxyTest extends TestCase{

	/**
	 * @param string[] $headers
	 * @phpstan-param list<string> $headers
	 */
	private static function request(array $headers) : HttpRequest{
		$head = "POST /v1/join/1 HTTP/1.1\r\nHost: h";
		foreach($headers as $header){
			$head .= "\r\n" . $header;
		}

		return HttpRequest::parse($head);
	}

	private static function forwardedFor() : ReverseProxy{
		return new ReverseProxy(ReverseProxy::HEADER_FORWARDED_FOR, ["10.0.0.0/8"]);
	}

	public function testOnlyTheConfiguredHeaderIsRead() : void{
		$realIp = new ReverseProxy(ReverseProxy::HEADER_REAL_IP, ["10.0.0.0/8"]);

		self::assertSame("203.0.113.7", $realIp->clientAddress(self::request(["X-Forwarded-For: 198.51.100.9", "X-Real-IP: 203.0.113.7"])));
		self::assertNull($realIp->clientAddress(self::request(["X-Forwarded-For: 198.51.100.9", "CF-Connecting-IP: 198.51.100.9"])));
		self::assertNull(self::forwardedFor()->clientAddress(self::request(["X-Real-IP: 198.51.100.9", "CF-Connecting-IP: 198.51.100.9"])));
	}

	public function testHeaderNameIsNotCaseSensitive() : void{
		$proxy = new ReverseProxy("x-forwarded-for", ["10.0.0.0/8"]);

		self::assertSame("203.0.113.7", $proxy->clientAddress(self::request(["X-Forwarded-For: 1.2.3.4, 203.0.113.7"])));
	}

	/**
	 * Proxies append addresses to X-Forwarded-For from left to right, so the most recent proxy is on the right.
	 */
	public function testForwardedForIsReadFromTheRight() : void{
		$request = self::request(["X-Forwarded-For: 1.2.3.4, 203.0.113.7"]);

		self::assertSame("203.0.113.7", self::forwardedFor()->clientAddress($request));
	}

	/**
	 * Skips trusted intermediate proxies when traversing X-Forwarded-For from the right to resolve the client IP.
	 */
	public function testForwardedForSkipsTrustedProxies() : void{
		$request = self::request(["X-Forwarded-For: 1.2.3.4, 203.0.113.7, 10.0.0.5, 10.0.0.6"]);

		self::assertSame("203.0.113.7", self::forwardedFor()->clientAddress($request));
	}

	public function testForwardedForOfOnlyTrustedHopsNamesTheLeftmost() : void{
		self::assertSame("10.0.0.5", self::forwardedFor()->clientAddress(self::request(["X-Forwarded-For: 10.0.0.5"])));
		self::assertSame("10.0.0.5", self::forwardedFor()->clientAddress(self::request(["X-Forwarded-For: 10.0.0.5, 10.0.0.6"])));
	}

	/**
	 * Skipping the entry would land on one the client wrote.
	 */
	public function testForwardedForStopsAtAnEntryThatIsNotAnAddress() : void{
		self::assertNull(self::forwardedFor()->clientAddress(self::request(["X-Forwarded-For: 203.0.113.7, unknown"])));
		self::assertNull(self::forwardedFor()->clientAddress(self::request(["X-Forwarded-For: 203.0.113.7, unknown, 10.0.0.5"])));
		self::assertNull(self::forwardedFor()->clientAddress(self::request(["X-Forwarded-For: 203.0.113.7,"])));
		self::assertSame("203.0.113.7", self::forwardedFor()->clientAddress(self::request(["X-Forwarded-For: unknown, 203.0.113.7"])));
	}

	public function testRepeatedForwardedForLinesAreOneList() : void{
		$request = self::request(["X-Forwarded-For: 1.2.3.4", "X-Forwarded-For: 203.0.113.7", "X-Forwarded-For: 10.0.0.5"]);

		self::assertSame("203.0.113.7", self::forwardedFor()->clientAddress($request));
	}

	public function testSingleAddressHeaderMustHoldExactlyOneAddress() : void{
		$proxy = new ReverseProxy(ReverseProxy::HEADER_REAL_IP, ["10.0.0.0/8"]);

		self::assertNull($proxy->clientAddress(self::request(["X-Real-IP: unknown"])));
		self::assertNull($proxy->clientAddress(self::request(["X-Real-IP: 198.51.100.9, 203.0.113.7"])));
		self::assertNull($proxy->clientAddress(self::request(["X-Real-IP: 198.51.100.9", "X-Real-IP: 203.0.113.7"])));
		self::assertNull($proxy->clientAddress(self::request([])));
	}

	public function testBracketsAndPortsAreStripped() : void{
		$proxy = self::forwardedFor();

		self::assertSame("2001:db8::7", $proxy->clientAddress(self::request(["X-Forwarded-For: [2001:db8::7]:41000"])));
		self::assertSame("2001:db8::7", $proxy->clientAddress(self::request(["X-Forwarded-For: [2001:db8::7]"])));
		self::assertSame("203.0.113.7", $proxy->clientAddress(self::request(["X-Forwarded-For: 203.0.113.7:41000"])));
	}

	/**
	 * Dual-stack proxies report IPv4 clients in IPv4-mapped form.
	 */
	public function testAddressesAreGivenInCanonicalForm() : void{
		$proxy = self::forwardedFor();

		self::assertSame("203.0.113.7", $proxy->clientAddress(self::request(["X-Forwarded-For: ::ffff:203.0.113.7"])));
		self::assertSame("203.0.113.7", $proxy->clientAddress(self::request(["X-Forwarded-For: 203.0.113.7, ::ffff:10.0.0.5"])));
		self::assertSame("2001:db8::7", $proxy->clientAddress(self::request(["X-Forwarded-For: 2001:0DB8:0:0:0:0:0:7"])));
	}

	/**
	 * @dataProvider unusableHeaderProvider
	 */
	public function testUnusableHeaderIsRejected(string $header) : void{
		$this->expectException(\InvalidArgumentException::class);

		new ReverseProxy($header, ["10.0.0.0/8"]);
	}

	/**
	 * @phpstan-return iterable<string, array{string}>
	 */
	public static function unusableHeaderProvider() : iterable{
		yield "empty" => [""];
		yield "not a token" => ["X-Real-IP:"];
		yield "trailing line feed" => ["X-Real-IP\n"];
		yield "RFC 7239" => ["Forwarded"];
	}

	public function testTrustsNetworksAndSingleAddresses() : void{
		$proxy = new ReverseProxy(ReverseProxy::HEADER_FORWARDED_FOR, ["10.0.0.0/8", "192.0.2.1", "2001:db8::/32"]);

		self::assertTrue($proxy->trusts("10.255.0.1"));
		self::assertFalse($proxy->trusts("11.0.0.1"));
		self::assertTrue($proxy->trusts("192.0.2.1"));
		self::assertFalse($proxy->trusts("192.0.2.2"));
		self::assertTrue($proxy->trusts("2001:db8:ffff::1"));
		self::assertFalse($proxy->trusts("2001:db9::1"));
		self::assertFalse($proxy->trusts("not an address"));
	}

	public function testPrefixShorterThanAByteIsHonoured() : void{
		$proxy = new ReverseProxy(ReverseProxy::HEADER_FORWARDED_FOR, ["203.0.113.0/26"]);

		self::assertTrue($proxy->trusts("203.0.113.63"));
		self::assertFalse($proxy->trusts("203.0.113.64"));
	}

	public function testMalformedNetworkIsRejected() : void{
		$this->expectException(\InvalidArgumentException::class);

		new ReverseProxy(ReverseProxy::HEADER_FORWARDED_FOR, ["10.0.0.0/33"]);
	}
}

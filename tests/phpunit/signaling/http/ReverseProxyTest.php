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

	public function testCloudflarePrefersItsOwnHeaderOverForwardedFor() : void{
		$proxy = ReverseProxy::cloudflare();
		$request = self::request(["CF-Connecting-IP: 203.0.113.7", "X-Forwarded-For: 198.51.100.9"]);

		self::assertSame("203.0.113.7", $proxy->clientAddress($request));
	}

	public function testNginxFallsBackToForwardedForWithoutRealIp() : void{
		$proxy = ReverseProxy::nginx();

		self::assertSame("203.0.113.7", $proxy->clientAddress(self::request(["X-Real-IP: 203.0.113.7", "X-Forwarded-For: 198.51.100.9"])));
		self::assertSame("198.51.100.9", $proxy->clientAddress(self::request(["X-Forwarded-For: 198.51.100.9"])));
	}

	/**
	 * Proxies append addresses to X-Forwarded-For from left to right, so the most recent proxy is on the right.
	 */
	public function testForwardedForIsReadFromTheRight() : void{
		$proxy = ReverseProxy::caddy();
		$request = self::request(["X-Forwarded-For: 1.2.3.4, 203.0.113.7"]);

		self::assertSame("203.0.113.7", $proxy->clientAddress($request));
	}

	/**
	 * Skips trusted intermediate proxies when traversing X-Forwarded-For from the right to resolve the client IP.
	 */
	public function testForwardedForSkipsTrustedProxies() : void{
		$proxy = ReverseProxy::caddy(["10.0.0.0/8"]);
		$request = self::request(["X-Forwarded-For: 1.2.3.4, 203.0.113.7, 10.0.0.5, 10.0.0.6"]);

		self::assertSame("203.0.113.7", $proxy->clientAddress($request));
		self::assertNull($proxy->clientAddress(self::request(["X-Forwarded-For: 10.0.0.5"])));
	}

	public function testBracketsAndPortsAreStripped() : void{
		$proxy = ReverseProxy::caddy();

		self::assertSame("2001:db8::7", $proxy->clientAddress(self::request(["X-Forwarded-For: [2001:db8::7]:41000"])));
		self::assertSame("2001:db8::7", $proxy->clientAddress(self::request(["X-Forwarded-For: [2001:db8::7]"])));
		self::assertSame("203.0.113.7", $proxy->clientAddress(self::request(["X-Forwarded-For: 203.0.113.7:41000"])));
	}

	public function testEntriesThatAreNotAddressesAreIgnored() : void{
		$proxy = ReverseProxy::nginx();

		self::assertNull($proxy->clientAddress(self::request(["X-Real-IP: unknown"])));
		self::assertSame("203.0.113.7", $proxy->clientAddress(self::request(["X-Forwarded-For: 203.0.113.7, unknown"])));
		self::assertNull($proxy->clientAddress(self::request([])));
	}

	public function testTrustsNetworksAndSingleAddresses() : void{
		$proxy = ReverseProxy::nginx(["10.0.0.0/8", "192.0.2.1", "2001:db8::/32"]);

		self::assertTrue($proxy->trusts("10.255.0.1"));
		self::assertFalse($proxy->trusts("11.0.0.1"));
		self::assertTrue($proxy->trusts("192.0.2.1"));
		self::assertFalse($proxy->trusts("192.0.2.2"));
		self::assertTrue($proxy->trusts("2001:db8:ffff::1"));
		self::assertFalse($proxy->trusts("2001:db9::1"));
		self::assertFalse($proxy->trusts("not an address"));
	}

	public function testPrefixShorterThanAByteIsHonoured() : void{
		$proxy = ReverseProxy::nginx(["203.0.113.0/26"]);

		self::assertTrue($proxy->trusts("203.0.113.63"));
		self::assertFalse($proxy->trusts("203.0.113.64"));
	}

	/**
	 * When no trusted networks are configured, all peers are trusted by default.
	 */
	public function testNoNetworksTrustsEveryone() : void{
		self::assertTrue(ReverseProxy::cloudflare()->trusts("203.0.113.7"));
	}

	public function testMalformedNetworkIsRejected() : void{
		$this->expectException(\InvalidArgumentException::class);

		ReverseProxy::nginx(["10.0.0.0/33"]);
	}
}

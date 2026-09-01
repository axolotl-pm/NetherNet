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

final class HttpRequestTest extends TestCase{

	public function testParsesAWellFormedRequest() : void{
		$request = HttpRequest::parse("POST /v1/join/123?x=1 HTTP/1.1\r\nHost: example.test\r\nContent-Type: application/sdp\r\nContent-Length: 4");

		self::assertSame("POST", $request->method);
		self::assertSame("/v1/join/123?x=1", $request->target);
		self::assertSame("/v1/join/123", $request->getPath());
		self::assertSame("example.test", $request->getHeader("HOST"));
		self::assertSame(4, $request->getContentLength(1024));
		self::assertTrue($request->hasContentType("application/sdp"));
	}

	public function testContentTypeParametersAreIgnored() : void{
		$request = HttpRequest::parse("POST /v1/join/1 HTTP/1.1\r\nHost: h\r\nContent-Type: application/sdp; charset=utf-8");

		self::assertTrue($request->hasContentType("application/sdp"));
	}

	/**
	 * Everything below is a way for this parser and a proxy in front of it to
	 * disagree about where one request ends and the next begins. That disagreement
	 * is request smuggling, so each case is refused outright rather than resolved.
	 *
	 * @dataProvider smugglingProvider
	 */
	public function testAmbiguousFramingIsRefused(string $head) : void{
		$this->expectException(HttpException::class);
		HttpRequest::parse($head);
	}

	/**
	 * @return string[][]
	 * @phpstan-return array<string, array{string}>
	 */
	public static function smugglingProvider() : array{
		return [
			"transfer encoding beside a length" => ["POST /v1/join/1 HTTP/1.1\r\nHost: h\r\nContent-Length: 4\r\nTransfer-Encoding: chunked"],
			"transfer encoding alone" => ["POST /v1/join/1 HTTP/1.1\r\nHost: h\r\nTransfer-Encoding: chunked"],
			"two content lengths" => ["POST /v1/join/1 HTTP/1.1\r\nHost: h\r\nContent-Length: 4\r\nContent-Length: 5"],
			"two hosts" => ["GET /v1/join HTTP/1.1\r\nHost: a\r\nHost: b"],
			"space before the colon" => ["GET /v1/join HTTP/1.1\r\nHost: h\r\nContent-Length : 4"],
			"folded value" => ["GET /v1/join HTTP/1.1\r\nHost: h\r\nX-Thing: a\r\n b"],
			"control character in a value" => ["GET /v1/join HTTP/1.1\r\nHost: h\r\nX-Thing: a\x01b"],
			"header name is not a token" => ["GET /v1/join HTTP/1.1\r\nHost: h\r\nX Thing: a"],
			"no host" => ["GET /v1/join HTTP/1.1\r\nAccept: */*"],
			"http 1.0" => ["GET /v1/join HTTP/1.0\r\nHost: h"],
			"absolute form target" => ["GET http://example.test/v1/join HTTP/1.1\r\nHost: h"],
			"lowercase method" => ["get /v1/join HTTP/1.1\r\nHost: h"],
			"empty" => [""]
		];
	}

	public function testMissingContentLengthOnAPostIsRefused() : void{
		$request = HttpRequest::parse("POST /v1/join/1 HTTP/1.1\r\nHost: h\r\nContent-Type: application/sdp");

		$this->expectException(HttpException::class);
		$request->getContentLength(1024);
	}

	public function testOversizedContentLengthIsRefused() : void{
		$request = HttpRequest::parse("POST /v1/join/1 HTTP/1.1\r\nHost: h\r\nContent-Length: 2000");

		$this->expectException(HttpException::class);
		$request->getContentLength(1024);
	}

	/**
	 * A length that would saturate to PHP_INT_MAX has to be caught as too large,
	 * not silently wrapped into something that passes the limit check.
	 */
	public function testAbsurdContentLengthIsRefusedRatherThanWrapping() : void{
		$request = HttpRequest::parse("POST /v1/join/1 HTTP/1.1\r\nHost: h\r\nContent-Length: 99999999999999999999999999");

		$this->expectException(HttpException::class);
		$request->getContentLength(1048576);
	}

	public function testNonNumericContentLengthIsRefused() : void{
		$request = HttpRequest::parse("POST /v1/join/1 HTTP/1.1\r\nHost: h\r\nContent-Length: 4a");

		$this->expectException(HttpException::class);
		$request->getContentLength(1024);
	}

	public function testBareLineFeedsAreAccepted() : void{
		$request = HttpRequest::parse("GET /v1/join HTTP/1.1\nHost: example.test");

		self::assertSame("example.test", $request->getHeader("host"));
	}
}

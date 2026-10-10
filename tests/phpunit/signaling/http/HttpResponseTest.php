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

final class HttpResponseTest extends TestCase{

	public function testResponseDeclaresItsLengthAndClosesTheConnection() : void{
		$response = new HttpResponse(HttpStatus::OK, "answer", "application/sdp", ["X-Test" => "1"]);

		self::assertSame(
			"HTTP/1.1 200 OK\r\nContent-Type: application/sdp\r\nX-Test: 1\r\nContent-Length: 6\r\nConnection: close\r\n\r\nanswer",
			$response->encode()
		);
	}

	/**
	 * A HEAD response describes the body a GET would have had without sending it.
	 */
	public function testBodyCanBeOmittedWhileItsLengthIsKept() : void{
		$encoded = (new HttpResponse(HttpStatus::OK, "answer"))->encode(omitBody: true);

		self::assertStringContainsString("Content-Length: 6\r\n", $encoded);
		self::assertStringEndsWith("\r\n\r\n", $encoded);
	}
}

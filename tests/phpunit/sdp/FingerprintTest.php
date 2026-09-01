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

namespace pocketmine\nethernet\sdp;

use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase{

	/**
	 * Both peers rebuild these bytes independently and one signs them, so a single
	 * byte of difference makes every assertion fail with no useful diagnostic.
	 * This pins the exact form rather than trusting a JSON encoder to keep the key
	 * order and spacing it happens to produce today.
	 */
	public function testCanonicalPayloadIsByteExact() : void{
		$fingerprint = new Fingerprint("sha-256", "00:11:22:33:44:55:66:77");

		self::assertSame(
			'{"fingerprint":[{"algorithm":"sha-256","digest":"00:11:22:33:44:55:66:77"}]}',
			$fingerprint->toCanonicalPayload()
		);
	}

	public function testParseSplitsAlgorithmFromDigest() : void{
		$fingerprint = Fingerprint::parse("sha-256 AA:BB");

		self::assertSame("sha-256", $fingerprint->getAlgorithm());
		self::assertSame("AA:BB", $fingerprint->getDigest());
		self::assertSame("sha-256 AA:BB", $fingerprint->toAttributeValue());
	}

	/**
	 * Implementations disagree on the case of hex digests, so comparing them
	 * literally would reject a peer that is saying the same thing.
	 */
	public function testDigestsCompareWithoutRegardToCase() : void{
		self::assertTrue((new Fingerprint("sha-256", "ab:cd"))->equals(new Fingerprint("SHA-256", "AB:CD")));
		self::assertFalse((new Fingerprint("sha-256", "ab:cd"))->equals(new Fingerprint("sha-256", "ab:ce")));
	}

	/**
	 * @dataProvider malformedValueProvider
	 */
	public function testMalformedValuesAreRefused(string $value) : void{
		$this->expectException(SdpException::class);
		Fingerprint::parse($value);
	}

	/**
	 * @return string[][]
	 * @phpstan-return array<string, array{string}>
	 */
	public static function malformedValueProvider() : array{
		return [
			"no digest" => ["sha-256"],
			"trailing junk" => ["sha-256 AA:BB extra"],
			"digest is not hex" => ["sha-256 zz:zz"],
			"digest without separators" => ["sha-256 AABB"],
			"digest with odd nibble" => ["sha-256 AA:B"],
			"empty" => [""]
		];
	}

	/**
	 * A value needing JSON escaping could never round-trip byte-identically
	 * against the peer's copy, so it is refused at construction rather than
	 * producing an assertion that silently never verifies.
	 */
	public function testValueRequiringEscapingIsRefused() : void{
		$this->expectException(SdpException::class);
		new Fingerprint("sha-256\"", "AA:BB");
	}
}

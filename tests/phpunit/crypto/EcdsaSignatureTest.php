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

namespace pocketmine\nethernet\crypto;

use PHPUnit\Framework\TestCase;
use function chr;
use function str_repeat;
use function substr;

final class EcdsaSignatureTest extends TestCase{

	/**
	 * A coordinate whose top bit is set has to gain a leading zero, or DER reads
	 * it as negative and OpenSSL refuses the signature.
	 */
	public function testHighBitCoordinateGainsALeadingZero() : void{
		$jose = str_repeat("\xff", 48) . str_repeat("\x01", 48);
		$der = EcdsaSignature::joseToDer($jose);

		//0x30 len 0x02 0x31 0x00 0xff...
		self::assertSame("\x30", $der[0]);
		self::assertSame("\x02", $der[2]);
		self::assertSame(chr(49), $der[3]);
		self::assertSame("\x00", $der[4]);
		self::assertSame($jose, EcdsaSignature::derToJose($der));
	}

	/**
	 * A short coordinate is left-padded back to the full width, since JOSE is
	 * fixed width and DER drops leading zeros.
	 */
	public function testShortCoordinateIsPaddedBackToFullWidth() : void{
		$jose = str_repeat("\x00", 47) . "\x07" . str_repeat("\x02", 48);

		self::assertSame($jose, EcdsaSignature::derToJose(EcdsaSignature::joseToDer($jose)));
	}

	/**
	 * @dataProvider malformedDerProvider
	 */
	public function testMalformedDerIsRefused(string $der) : void{
		$this->expectException(CryptoException::class);
		EcdsaSignature::derToJose($der);
	}

	/**
	 * @return string[][]
	 * @phpstan-return array<string, array{string}>
	 */
	public static function malformedDerProvider() : array{
		$valid = EcdsaSignature::joseToDer(str_repeat("\x01", 96));

		return [
			"empty" => [""],
			"not a sequence" => ["\x31\x02\x02\x00"],
			"length disagrees with the content" => [substr($valid, 0, -1)],
			"trailing data" => [$valid . "\x00"],
			"long form length" => ["\x30\x81\x02\x02\x00"]
		];
	}

	public function testJoseSignatureOfTheWrongWidthIsRefused() : void{
		$this->expectException(CryptoException::class);
		EcdsaSignature::joseToDer(str_repeat("\x01", 95));
	}
}

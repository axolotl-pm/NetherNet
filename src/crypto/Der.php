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

use function chr;
use function ltrim;
use function pack;
use function strlen;

/**
 * Minimal ASN.1 DER encoder for binary structures not directly exposed by OpenSSL.
 */
final class Der{

	/** Longest body this can express, since lengths are written in at most four bytes. */
	private const MAX_LENGTH = 0xffffffff;

	private function __construct(){
		//NOOP
	}

	/**
	 * @throws CryptoException
	 */
	public static function encode(int $tag, string $body) : string{
		return chr($tag) . self::length(strlen($body)) . $body;
	}

	/**
	 * @throws CryptoException
	 */
	public static function sequence(string $body) : string{
		return self::encode(0x30, $body);
	}

	/**
	 * @throws CryptoException
	 */
	public static function integer(string $body) : string{
		return self::encode(0x02, $body);
	}

	/**
	 * @throws CryptoException
	 */
	public static function octetString(string $body) : string{
		return self::encode(0x04, $body);
	}

	/**
	 * Encodes a BIT STRING whose content is a whole number of bytes (unused bits = 0).
	 *
	 * @throws CryptoException
	 */
	public static function bitString(string $body) : string{
		return self::encode(0x03, "\x00" . $body);
	}

	/**
	 * Encodes a context-specific constructed tag (e.g. for optional SEC1 structures).
	 *
	 * @throws CryptoException
	 */
	public static function tagged(int $number, string $body) : string{
		return self::encode(0xa0 | $number, $body);
	}

	/**
	 * @throws CryptoException
	 */
	private static function length(int $length) : string{
		if($length <= 0x7f){
			return chr($length);
		}
		if($length > self::MAX_LENGTH){
			throw new CryptoException("DER body of $length bytes is too long to encode");
		}

		$bytes = ltrim(pack("N", $length), "\x00");

		return chr(0x80 | strlen($bytes)) . $bytes;
	}
}

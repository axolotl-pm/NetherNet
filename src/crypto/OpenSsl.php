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

use function openssl_error_string;
use function str_pad;
use function strlen;
use const STR_PAD_LEFT;

/**
 * Utility helpers for OpenSSL error handling.
 */
final class OpenSsl{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the most recent OpenSSL error message while draining the error queue.
	 */
	public static function lastError() : string{
		$message = null;
		while(($error = openssl_error_string()) !== false){
			$message = $error;
		}

		return $message ?? "no detail available";
	}

	/**
	 * Restores a curve component to its full width.
	 *
	 * OpenSSL hands back these numbers with leading zero bytes removed, so roughly
	 * one value in 256 arrives a byte short. The formats that carry them are
	 * fixed-width, and a short one silently shifts everything after it.
	 *
	 * @throws CryptoException if the value is wider than the curve allows
	 */
	public static function padCoordinate(string $value, int $size) : string{
		if(strlen($value) > $size){
			throw new CryptoException("Curve component is " . strlen($value) . " bytes, wider than the $size the curve allows");
		}

		return str_pad($value, $size, "\x00", STR_PAD_LEFT);
	}
}

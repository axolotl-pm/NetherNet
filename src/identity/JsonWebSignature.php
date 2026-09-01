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

namespace pocketmine\nethernet\identity;

use pocketmine\nethernet\crypto\Base64Url;
use pocketmine\nethernet\crypto\CryptoException;
use pocketmine\nethernet\crypto\EcdsaSignature;
use pocketmine\nethernet\crypto\OpenSsl;
use function count;
use function explode;
use function is_array;
use function json_decode;
use function openssl_sign;
use function openssl_verify;
use const OPENSSL_ALGO_SHA384;

/**
 * Handles creation and verification of ES384 JWS tokens and detached signatures.
 */
final class JsonWebSignature{

	public const ALGORITHM = "ES384";

	/**
	 * Canonical protected header for ES384 JWS signatures.
	 */
	private const PROTECTED_HEADER = '{"alg":"ES384"}';

	private function __construct(){}

	/**
	 * @throws CryptoException
	 */
	public static function signRaw(string $signingInput, \OpenSSLAsymmetricKey $privateKey) : string{
		$der = "";
		if(!openssl_sign($signingInput, $der, $privateKey, OPENSSL_ALGO_SHA384)){
			throw new CryptoException("OpenSSL failed to sign: " . OpenSsl::lastError());
		}

		return EcdsaSignature::derToJose($der);
	}

	/**
	 * Verifies an ES384 signature over raw signing input.
	 *
	 * @throws CryptoException
	 */
	public static function verifyRaw(string $signingInput, string $signature, PublicKey $publicKey) : bool{
		$result = openssl_verify(
			$signingInput,
			EcdsaSignature::joseToDer($signature),
			$publicKey->getOpenSslKey(),
			OPENSSL_ALGO_SHA384
		);

		return match($result){
			1 => true,
			0 => false,
			default => throw new CryptoException("OpenSSL failed to verify: " . OpenSsl::lastError())
		};
	}

	/**
	 * Produces a detached JWS signature (RFC 7515 Appendix F) over the payload.
	 *
	 * @throws CryptoException
	 */
	public static function signDetached(string $payload, \OpenSSLAsymmetricKey $privateKey) : string{
		$header = Base64Url::encode(self::PROTECTED_HEADER);
		$signature = self::signRaw($header . "." . Base64Url::encode($payload), $privateKey);

		return $header . ".." . Base64Url::encode($signature);
	}

	/**
	 * @throws CryptoException
	 */
	public static function verifyDetached(string $detached, string $payload, PublicKey $publicKey) : bool{
		$parts = explode(".", $detached, limit: 4);
		if(count($parts) !== 3){
			throw new CryptoException("Detached signature must have exactly three period-separated parts");
		}
		if($parts[1] !== ""){
			throw new CryptoException("Detached signature must not carry a payload");
		}

		self::checkAlgorithm($parts[0]);

		return self::verifyRaw(
			$parts[0] . "." . Base64Url::encode($payload),
			Base64Url::decode($parts[2]),
			$publicKey
		);
	}

	/**
	 * @throws CryptoException
	 */
	private static function checkAlgorithm(string $encodedHeader) : void{
		$decoded = json_decode(Base64Url::decode($encodedHeader), associative: true);
		if(!is_array($decoded)){
			throw new CryptoException("Detached signature header is not a JSON object");
		}
		if(($decoded["alg"] ?? null) !== self::ALGORITHM){
			throw new CryptoException("Detached signature must use " . self::ALGORITHM);
		}
	}
}

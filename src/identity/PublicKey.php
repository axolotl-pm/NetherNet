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
use pocketmine\nethernet\crypto\OpenSsl;
use function base64_decode;
use function base64_encode;
use function chr;
use function hash;
use function hash_equals;
use function hex2bin;
use function is_array;
use function is_string;
use function openssl_pkey_get_details;
use function openssl_pkey_get_public;
use function preg_match;
use function sprintf;
use function str_replace;
use function strlen;

final class PublicKey{

	private const CURVE_NAME = "P-384";
	private const COORDINATE_SIZE = 48;

	/**
	 * Fixed ASN.1 prefix of a P-384 SubjectPublicKeyInfo (ecPublicKey OID and secp384r1 OID).
	 */
	private const P384_ALGORITHM_IDENTIFIER = "301006072a8648ce3d020106052b81040022";

	private function __construct(
		private readonly \OpenSSLAsymmetricKey $key,
		private readonly string $der
	){}

	/**
	 * @throws CryptoException
	 */
	public static function fromClaim(mixed $value) : self{
		if(is_string($value)){
			return self::fromBase64Der($value);
		}
		if(is_array($value)){
			return self::fromJwk($value);
		}

		throw new CryptoException("Public key claim must be a base64 string or a JWK object");
	}

	/**
	 * @throws CryptoException
	 */
	public static function fromBase64Der(string $base64) : self{
		$der = base64_decode($base64, true);
		if($der === false || $der === ""){
			throw new CryptoException("Public key is not valid base64");
		}

		return self::fromDer($der);
	}

	/**
	 * @phpstan-param array<array-key, mixed> $jwk
	 *
	 * @throws CryptoException
	 */
	public static function fromJwk(array $jwk) : self{
		if(($jwk["kty"] ?? null) !== "EC"){
			throw new CryptoException("Public key JWK must have kty EC");
		}
		if(($jwk["crv"] ?? null) !== self::CURVE_NAME){
			throw new CryptoException("Public key JWK must use curve " . self::CURVE_NAME);
		}

		$x = $jwk["x"] ?? null;
		$y = $jwk["y"] ?? null;
		if(!is_string($x) || !is_string($y)){
			throw new CryptoException("Public key JWK is missing its coordinates");
		}

		$xBytes = Base64Url::decode($x);
		$yBytes = Base64Url::decode($y);
		if(strlen($xBytes) !== self::COORDINATE_SIZE || strlen($yBytes) !== self::COORDINATE_SIZE){
			throw new CryptoException("Public key JWK coordinates must be " . self::COORDINATE_SIZE . " bytes each");
		}

		return self::fromDer(self::encodeSubjectPublicKeyInfo($xBytes, $yBytes));
	}

	/**
	 * @throws CryptoException
	 */
	private static function encodeSubjectPublicKeyInfo(string $x, string $y) : string{
		$algorithmIdentifier = hex2bin(self::P384_ALGORITHM_IDENTIFIER);
		if($algorithmIdentifier === false){
			throw new CryptoException("Algorithm identifier constant is not valid hex");
		}

		$point = "\x04" . $x . $y;
		$bitString = "\x03" . chr(strlen($point) + 1) . "\x00" . $point;
		$body = $algorithmIdentifier . $bitString;

		return "\x30" . chr(strlen($body)) . $body;
	}

	/**
	 * @throws CryptoException
	 */
	public static function fromDer(string $der) : self{
		$key = openssl_pkey_get_public(self::derToPem($der));
		if($key === false){
			throw new CryptoException("OpenSSL rejected the public key: " . OpenSsl::lastError());
		}

		return new self($key, $der);
	}

	/**
	 * @throws CryptoException
	 */
	public static function fromOpenSslKey(\OpenSSLAsymmetricKey $key) : self{
		return self::fromDer(self::extractDer($key));
	}

	private static function derToPem(string $der) : string{
		return sprintf("-----BEGIN PUBLIC KEY-----\n%s\n-----END PUBLIC KEY-----\n", base64_encode($der));
	}

	/**
	 * @throws CryptoException
	 */
	private static function extractDer(\OpenSSLAsymmetricKey $key) : string{
		$details = openssl_pkey_get_details($key);
		if($details === false || !isset($details["key"]) || !is_string($details["key"])){
			throw new CryptoException("Could not read the public key out of the OpenSSL handle");
		}

		if(preg_match("@^-----BEGIN[A-Z\d ]+PUBLIC KEY-----\n([A-Za-z\d+/\n=]+)\n-----END[A-Z\d ]+PUBLIC KEY-----\n$@", $details["key"], $matches) !== 1){
			throw new CryptoException("OpenSSL produced a public key in an unexpected format");
		}

		$der = base64_decode(str_replace("\n", "", $matches[1]), true);
		if($der === false){
			throw new CryptoException("OpenSSL produced a public key that is not valid base64");
		}

		return $der;
	}

	public function getOpenSslKey() : \OpenSSLAsymmetricKey{ return $this->key; }

	public function toCpk() : string{
		return base64_encode($this->der);
	}

	public function getDigest(string $algorithm = "sha256") : string{
		return hash($algorithm, $this->der, binary: true);
	}

	public function equals(self $other) : bool{
		return hash_equals($this->der, $other->der);
	}
}

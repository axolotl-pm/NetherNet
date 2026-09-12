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
use function count;
use function explode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function time;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Represents and validates JSON Web Tokens (JWT, RFC 7519) in NetherNet identity assertions.
 *
 * Handles both client GameServerTokens issued by Minecraft Auth Services and self-signed Server Identity JWTs.
 */
final class JsonWebToken{

	/** The `cpk` (Client Public Key / Operator Public Key) claim required in NetherNet JWTs. */
	public const CLAIM_PUBLIC_KEY = "cpk";
	public const HEADER_PUBLIC_KEY = "x5u";

	public const DEFAULT_LEEWAY_SECONDS = 60;

	/**
	 * @phpstan-param array<string, mixed> $header
	 * @phpstan-param array<string, mixed> $claims
	 */
	private function __construct(
		private readonly array $header,
		private readonly array $claims,
		private readonly string $signingInput,
		private readonly string $signature
	){}

	/**
	 * Decodes a JWT without verifying its signature.
	 *
	 * @throws CryptoException
	 */
	public static function parse(string $compact) : self{
		$parts = explode(".", $compact, limit: 4);
		if(count($parts) !== 3){
			throw new CryptoException("Expected 3 parts in JWT, got " . count($parts));
		}

		return new self(
			self::decodeJsonObject($parts[0], "header"),
			self::decodeJsonObject($parts[1], "claims"),
			$parts[0] . "." . $parts[1],
			Base64Url::decode($parts[2])
		);
	}

	/**
	 * @return mixed[]
	 * @phpstan-return array<string, mixed>
	 *
	 * @throws CryptoException
	 */
	private static function decodeJsonObject(string $encoded, string $what) : array{
		$decoded = json_decode(Base64Url::decode($encoded), associative: true);
		if(!is_array($decoded)){
			throw new CryptoException("Token $what is not a JSON object");
		}

		/** @phpstan-var array<string, mixed> $decoded */
		return $decoded;
	}

	/**
	 * @throws CryptoException
	 */
	public static function sign(PublicKey $publicKey, \OpenSSLAsymmetricKey $privateKey, int $lifetimeSeconds = 60) : string{
		if($lifetimeSeconds < 1){
			throw new \InvalidArgumentException("Token lifetime must be positive, got $lifetimeSeconds");
		}

		$issuedAt = time();
		$encodedKey = $publicKey->toCpk();

		$header = self::encodeJson([
			"alg" => JsonWebSignature::ALGORITHM,
			self::HEADER_PUBLIC_KEY => $encodedKey
		]);
		$claims = self::encodeJson([
			"exp" => $issuedAt + $lifetimeSeconds,
			"iat" => $issuedAt,
			self::CLAIM_PUBLIC_KEY => $encodedKey
		]);

		$signingInput = Base64Url::encode($header) . "." . Base64Url::encode($claims);

		return $signingInput . "." . Base64Url::encode(JsonWebSignature::signRaw($signingInput, $privateKey));
	}

	/**
	 * @phpstan-param array<string, mixed> $value
	 *
	 * @throws CryptoException
	 */
	private static function encodeJson(array $value) : string{
		try{
			return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		}catch(\JsonException $e){
			throw new CryptoException("Could not encode token JSON: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * @phpstan-return array<string, mixed>
	 */
	public function getHeader() : array{ return $this->header; }

	/**
	 * @phpstan-return array<string, mixed>
	 */
	public function getClaims() : array{ return $this->claims; }

	public function getAlgorithm() : ?string{
		$algorithm = $this->header["alg"] ?? null;

		return is_string($algorithm) ? $algorithm : null;
	}

	/**
	 * @throws CryptoException
	 */
	public function getPublicKey() : PublicKey{
		if(!isset($this->claims[self::CLAIM_PUBLIC_KEY])){
			throw new CryptoException("Token has no " . self::CLAIM_PUBLIC_KEY . " claim");
		}

		return PublicKey::fromClaim($this->claims[self::CLAIM_PUBLIC_KEY]);
	}

	/**
	 * @throws CryptoException
	 */
	public function verifySelfSigned() : void{
		if($this->getAlgorithm() !== JsonWebSignature::ALGORITHM){
			throw new CryptoException("Self-signed tokens must use " . JsonWebSignature::ALGORITHM);
		}
		if(!JsonWebSignature::verifyRaw($this->signingInput, $this->signature, $this->getPublicKey())){
			throw new CryptoException("Token signature does not match the key it carries");
		}
	}

	/**
	 * @throws CryptoException
	 */
	public function checkTimestamps(int $leewaySeconds = self::DEFAULT_LEEWAY_SECONDS, ?int $now = null) : void{
		$now ??= time();

		$notBefore = $this->readTimestamp("nbf");
		if($notBefore !== null && $now + $leewaySeconds < $notBefore){
			throw new CryptoException("Token is not valid yet");
		}

		$expiry = $this->readTimestamp("exp");
		if($expiry !== null && $now - $leewaySeconds >= $expiry){
			throw new CryptoException("Token has expired");
		}
	}

	/**
	 * @throws CryptoException
	 */
	private function readTimestamp(string $claim) : ?int{
		$value = $this->claims[$claim] ?? null;
		if($value === null){
			return null;
		}
		if(is_int($value)){
			return $value;
		}
		if(is_float($value)){
			return (int) $value;
		}

		throw new CryptoException("Token claim $claim is not a number");
	}
}

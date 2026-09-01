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

use pocketmine\nethernet\crypto\CryptoException;
use pocketmine\nethernet\sdp\Fingerprint;
use function base64_decode;
use function base64_encode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function substr_count;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Represents the WebRTC identity assertion attribute (`a=identity`, RFC 8827) exchanged in SDP.
 *
 * Encloses an `idp` dictionary and an `assertion` string containing a JWT (`GameServerToken` in offers,
 * or Server Identity JWT in answers) along with detached JWS fingerprints covering DTLS certificate digests.
 */
final class IdentityAssertion{

	public const PROTOCOL_DEFAULT = "default";

	private function __construct(
		private readonly string $domain,
		private readonly string $protocol,
		private readonly string $token,
		private readonly string $fingerprints
	){}

	/**
	 * @throws CryptoException
	 */
	public static function create(string $domain, string $token, string $fingerprints, string $protocol = self::PROTOCOL_DEFAULT) : self{
		$assertion = new self($domain, $protocol, $token, $fingerprints);
		$assertion->validate();

		return $assertion;
	}

	/**
	 * Decodes the base64 value of an `a=identity` attribute.
	 *
	 * @throws CryptoException
	 */
	public static function parse(string $attributeValue) : self{
		$envelope = base64_decode($attributeValue, true);
		if($envelope === false || $envelope === ""){
			throw new CryptoException("Identity attribute is not valid base64");
		}

		$decoded = self::decodeJsonObject($envelope, "identity envelope");

		$idp = $decoded["idp"] ?? null;
		if(!is_array($idp)){
			throw new CryptoException("Identity envelope has no idp object");
		}
		$domain = $idp["domain"] ?? null;
		$protocol = $idp["protocol"] ?? null;
		if(!is_string($domain) || !is_string($protocol)){
			throw new CryptoException("Identity provider is missing its domain or protocol");
		}

		$inner = $decoded["assertion"] ?? null;
		if(!is_string($inner)){
			throw new CryptoException("Identity envelope assertion must be a JSON string");
		}
		$assertion = self::decodeJsonObject($inner, "identity assertion");

		$token = $assertion["token"] ?? null;
		$fingerprints = $assertion["fingerprints"] ?? null;
		if(!is_string($token) || !is_string($fingerprints)){
			throw new CryptoException("Identity assertion is missing its token or fingerprints");
		}

		$result = new self($domain, $protocol, $token, $fingerprints);
		$result->validate();

		return $result;
	}

	/**
	 * @return mixed[]
	 * @phpstan-return array<string, mixed>
	 *
	 * @throws CryptoException
	 */
	private static function decodeJsonObject(string $json, string $what) : array{
		$decoded = json_decode($json, associative: true);
		if(!is_array($decoded)){
			throw new CryptoException("Malformed $what");
		}

		/** @phpstan-var array<string, mixed> $decoded */
		return $decoded;
	}

	/**
	 * Validates the basic structural requirements of the assertion fields.
	 *
	 * @throws CryptoException
	 */
	private function validate() : void{
		if($this->protocol !== self::PROTOCOL_DEFAULT){
			throw new CryptoException("Identity provider protocol must be " . self::PROTOCOL_DEFAULT . ", got $this->protocol");
		}
		if($this->domain === ""){
			throw new CryptoException("Identity provider domain must not be empty");
		}
		if($this->token === "" || substr_count($this->token, ".") !== 2){
			throw new CryptoException("Identity token is not a compact JWS");
		}
		if($this->fingerprints === "" || substr_count($this->fingerprints, ".") !== 2){
			throw new CryptoException("Fingerprint assertion is not a compact JWS");
		}
	}

	public function getDomain() : string{ return $this->domain; }

	public function getProtocol() : string{ return $this->protocol; }

	/** Returns the detached JWS (compact serialization) covering the SDP canonical fingerprint JSON. */
	public function getFingerprints() : string{ return $this->fingerprints; }

	public function getRawToken() : string{ return $this->token; }

	/**
	 * @throws CryptoException
	 */
	public function getToken() : JsonWebToken{
		return JsonWebToken::parse($this->token);
	}

	/**
	 * Encodes the assertion into a base64 string for the `a=identity` SDP attribute.
	 *
	 * @throws CryptoException
	 */
	public function encode() : string{
		try{
			$inner = json_encode([
				"fingerprints" => $this->fingerprints,
				"token" => $this->token
			], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

			$envelope = json_encode([
				"assertion" => $inner,
				"idp" => [
					"domain" => $this->domain,
					"protocol" => $this->protocol
				]
			], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		}catch(\JsonException $e){
			throw new CryptoException("Could not encode the identity assertion: " . $e->getMessage(), 0, $e);
		}

		return base64_encode($envelope);
	}

	/**
	 * Verifies that the detached JWS was signed by the `cpk` key in the JWT for the expected DTLS fingerprint.
	 *
	 * @throws CryptoException
	 */
	public function verifyFingerprint(Fingerprint $fingerprint) : PublicKey{
		$publicKey = $this->getToken()->getPublicKey();
		if(!JsonWebSignature::verifyDetached($this->fingerprints, $fingerprint->toCanonicalPayload(), $publicKey)){
			throw new CryptoException("Fingerprint assertion was not signed by the key in the token");
		}

		return $publicKey;
	}
}

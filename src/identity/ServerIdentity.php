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
use pocketmine\nethernet\crypto\Der;
use pocketmine\nethernet\crypto\OpenSsl;
use function base64_encode;
use function chunk_split;
use function hex2bin;
use function is_array;
use function is_string;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_pkey_new;

final class ServerIdentity{

	public const CURVE_NAME = "secp384r1";

	/** Coordinate width for P-384 */
	private const COORDINATE_SIZE = 48;

	/** OID 1.2.840.10045.2.1, id-ecPublicKey. */
	private const OID_EC_PUBLIC_KEY = "06072a8648ce3d0201";

	/** OID 1.3.132.0.34, secp384r1. */
	private const OID_SECP384R1 = "06052b81040022";

	private function __construct(
		private readonly \OpenSSLAsymmetricKey $privateKey,
		private readonly PublicKey $publicKey
	){}

	/**
	 * @throws CryptoException
	 */
	public static function generate() : self{
		$key = openssl_pkey_new(["ec" => ["curve_name" => self::CURVE_NAME]]);
		if($key === false){
			throw new CryptoException("OpenSSL could not generate a key: " . OpenSsl::lastError());
		}

		return new self($key, PublicKey::fromOpenSslKey($key));
	}

	/**
	 * @throws CryptoException
	 */
	public static function fromPrivateKeyPem(string $pem, ?string $passphrase = null) : self{
		$key = openssl_pkey_get_private($pem, $passphrase);
		if($key === false){
			throw new CryptoException("OpenSSL could not read the private key: " . OpenSsl::lastError());
		}

		return new self($key, PublicKey::fromOpenSslKey($key));
	}

	/**
	 * @throws CryptoException
	 */
	public function exportPrivateKeyPem() : string{
		[$d, $x, $y] = $this->readComponents();

		$ecPrivateKey = Der::sequence(
			Der::integer("\x01") .
			Der::octetString($d) .
			Der::tagged(1, Der::bitString("\x04" . $x . $y))
		);

		$algorithm = hex2bin(self::OID_EC_PUBLIC_KEY . self::OID_SECP384R1);
		if($algorithm === false){
			throw new CryptoException("Algorithm identifier is not valid hex");
		}

		$der = Der::sequence(
			Der::integer("\x00") .
			Der::sequence($algorithm) .
			Der::octetString($ecPrivateKey)
		);

		return "-----BEGIN PRIVATE KEY-----\n"
			. chunk_split(base64_encode($der), 64, "\n")
			. "-----END PRIVATE KEY-----\n";
	}

	/**
	 * @phpstan-return array{string, string, string}
	 *
	 * @throws CryptoException
	 */
	private function readComponents() : array{
		$details = openssl_pkey_get_details($this->privateKey);
		if($details === false || !isset($details["ec"]) || !is_array($details["ec"])){
			throw new CryptoException("Could not read the key components out of the OpenSSL handle");
		}

		$components = [];
		foreach(["d", "x", "y"] as $name){
			$value = $details["ec"][$name] ?? null;
			if(!is_string($value) || $value === ""){
				throw new CryptoException("The key is missing its $name component");
			}
			$components[] = OpenSsl::padCoordinate($value, self::COORDINATE_SIZE);
		}

		return [$components[0], $components[1], $components[2]];
	}

	public function getPublicKey() : PublicKey{ return $this->publicKey; }

	/** @internal */
	public function getPrivateKey() : \OpenSSLAsymmetricKey{ return $this->privateKey; }
}

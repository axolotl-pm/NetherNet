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

/**
 * Manages an operator's long-lived P-384 keypair used for server identity assertions and client TOFU pinning.
 *
 * Nothing here goes through openssl_pkey_export(). That is the one OpenSSL call
 * that insists on reading a configuration file, and stock PHP on Windows has
 * none, so a host that would otherwise work fails to start with an error about a
 * missing config rather than anything to do with keys. Writing the PKCS#8
 * ourselves keeps the file a normal PEM that other tools can read.
 */
final class ServerIdentity{

	public const CURVE_NAME = "secp384r1";

	/** Coordinate width for P-384: each of d, x and y is exactly this many bytes. */
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
		//the nested "ec" form, which is what PocketMine uses. The flat
		//private_key_type/curve_name form asks OpenSSL to resolve defaults out of a
		//configuration file and fails without one
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
	 * Exports the private key as an unencrypted PKCS#8 PEM.
	 *
	 * The structure is the one OpenSSL itself emits for this curve:
	 * PrivateKeyInfo wrapping a SEC1 ECPrivateKey, with the curve named once in
	 * the algorithm identifier and the public point repeated in the inner [1].
	 *
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
	 * The private scalar and public point, each padded to the curve's width.
	 *
	 * OpenSSL strips leading zero bytes, so a component that happens to start
	 * with one comes back short and has to be padded back out. Writing it short
	 * produces a PEM that parses but describes a different key.
	 *
	 * @return string[]
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

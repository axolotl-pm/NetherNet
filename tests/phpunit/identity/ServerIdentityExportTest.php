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

use PHPUnit\Framework\TestCase;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function openssl_sign;
use function openssl_verify;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use const OPENSSL_ALGO_SHA384;

/**
 * The key file this writes is the one clients pin the host by, so it has to be a
 * PEM other tools can read, and it has to survive a restart unchanged.
 *
 * It is written by hand rather than with openssl_pkey_export(), because that is
 * the only OpenSSL call in this library that insists on a configuration file and
 * stock PHP on Windows has none. These tests are what keeps the hand-written
 * encoding honest.
 */
final class ServerIdentityExportTest extends TestCase{

	public function testExportProducesAnUnencryptedPkcs8Pem() : void{
		$pem = ServerIdentity::generate()->exportPrivateKeyPem();

		self::assertTrue(str_starts_with($pem, "-----BEGIN PRIVATE KEY-----\n"));
		self::assertTrue(str_ends_with($pem, "-----END PRIVATE KEY-----\n"));
	}

	/** If OpenSSL cannot read it back, no other tool will either. */
	public function testOpenSslReadsBackWhatWeWrote() : void{
		$identity = ServerIdentity::generate();
		$reloaded = openssl_pkey_get_private($identity->exportPrivateKeyPem());

		self::assertNotFalse($reloaded);

		$original = openssl_pkey_get_details($identity->getPrivateKey());
		$parsed = openssl_pkey_get_details($reloaded);
		self::assertIsArray($original);
		self::assertIsArray($parsed);
		self::assertSame($original["ec"]["d"], $parsed["ec"]["d"]);
		self::assertSame($original["key"], $parsed["key"]);
	}

	/**
	 * A restart loads the file and must end up with the same identity, or every
	 * player who trusted this host on first use is prompted again.
	 */
	public function testIdentitySurvivesAWriteAndRead() : void{
		$identity = ServerIdentity::generate();
		$pem = $identity->exportPrivateKeyPem();

		$restored = ServerIdentity::fromPrivateKeyPem($pem);

		self::assertSame($identity->getPublicKey()->getDigest(), $restored->getPublicKey()->getDigest());
		self::assertSame($pem, $restored->exportPrivateKeyPem());
	}

	/** The restored key has to be the same signing key, not merely a similar one. */
	public function testASignatureVerifiesAgainstTheRestoredKey() : void{
		$identity = ServerIdentity::generate();
		$signature = "";
		self::assertTrue(openssl_sign("payload", $signature, $identity->getPrivateKey(), OPENSSL_ALGO_SHA384));

		$restored = ServerIdentity::fromPrivateKeyPem($identity->exportPrivateKeyPem());
		$details = openssl_pkey_get_details($restored->getPrivateKey());
		self::assertIsArray($details);

		$publicKey = openssl_pkey_get_public($details["key"]);
		self::assertNotFalse($publicKey);
		self::assertSame(1, openssl_verify("payload", $signature, $publicKey, OPENSSL_ALGO_SHA384));
	}

	/**
	 * OpenSSL strips leading zero bytes from curve components, so roughly one key
	 * in 256 has a scalar or coordinate that is a byte short. Writing it short
	 * produces a PEM that still parses but describes a different key, and the
	 * failure only appears for the unlucky operator whose key happened to be one
	 * of those. Generating a batch is what makes the case reachable at all.
	 */
	public function testKeysWithAShortComponentStillRoundTrip() : void{
		$short = 0;

		for($i = 0; $i < 300; ++$i){
			$identity = ServerIdentity::generate();

			$details = openssl_pkey_get_details($identity->getPrivateKey());
			self::assertIsArray($details);
			foreach(["d", "x", "y"] as $component){
				if(strlen($details["ec"][$component]) < 48){
					++$short;
				}
			}

			$pem = $identity->exportPrivateKeyPem();
			self::assertNotFalse(openssl_pkey_get_private($pem), "key #$i could not be read back");
			self::assertSame(
				$identity->getPublicKey()->getDigest(),
				ServerIdentity::fromPrivateKeyPem($pem)->getPublicKey()->getDigest(),
				"key #$i changed identity across the round trip"
			);
		}

		self::assertGreaterThan(0, $short, "no short component appeared in 900 draws, so this test proved nothing");
	}
}

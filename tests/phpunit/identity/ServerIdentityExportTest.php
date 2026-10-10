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
use function min;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function strlen;

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
	 * OpenSSL strips leading zero bytes from curve components, so roughly one key
	 * in 256 has a scalar or coordinate that is a byte short. Writing it short
	 * produces a PEM that still parses but describes a different key, and the
	 * failure only appears for the unlucky operator whose key happened to be one
	 * of those. Keys are drawn until one of them turns up.
	 */
	public function testKeyWithAShortComponentStillRoundTrips() : void{
		for($i = 0; $i < 5000; ++$i){
			$identity = ServerIdentity::generate();
			$details = openssl_pkey_get_details($identity->getPrivateKey());
			self::assertIsArray($details);
			if(min(strlen($details["ec"]["d"]), strlen($details["ec"]["x"]), strlen($details["ec"]["y"])) >= 48){
				continue;
			}

			$pem = $identity->exportPrivateKeyPem();
			self::assertNotFalse(openssl_pkey_get_private($pem), "the key could not be read back");
			self::assertSame(
				$identity->getPublicKey()->getDigest(),
				ServerIdentity::fromPrivateKeyPem($pem)->getPublicKey()->getDigest(),
				"the key changed identity across the round trip"
			);

			return;
		}

		self::fail("no key with a short component appeared, so this test proved nothing");
	}
}

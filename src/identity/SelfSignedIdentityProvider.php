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

/**
 * Generates self-signed server identity assertions (`a=identity`) for SDP answers.
 */
final class SelfSignedIdentityProvider implements IdentityProvider{

	/**
	 * @param string $domain               Partner domain identifier advertised in the `idp` object (default "self").
	 * @param int    $tokenLifetimeSeconds Validity lifetime for the minted Server Identity JWT in seconds.
	 */
	public function __construct(
		private readonly ServerIdentity $identity,
		private readonly string $domain = "self",
		private readonly int $tokenLifetimeSeconds = 60
	){
		if($domain === ""){
			throw new \InvalidArgumentException("Identity provider domain must not be empty");
		}
		if($tokenLifetimeSeconds < 1){
			throw new \InvalidArgumentException("Token lifetime must be positive, got $tokenLifetimeSeconds");
		}
	}

	/**
	 * @throws CryptoException
	 */
	public function issue(Fingerprint $localFingerprint) : IdentityAssertion{
		$privateKey = $this->identity->getPrivateKey();

		return IdentityAssertion::create(
			$this->domain,
			JsonWebToken::sign($this->identity->getPublicKey(), $privateKey, $this->tokenLifetimeSeconds),
			JsonWebSignature::signDetached($localFingerprint->toCanonicalPayload(), $privateKey)
		);
	}
}

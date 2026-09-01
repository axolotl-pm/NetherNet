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
 * Validates client SDP offer identity assertions (`a=identity`) against DTLS fingerprints and GameServerTokens.
 */
final class AssertionIdentityVerifier implements IdentityVerifier{

	/**
	 * @param bool $allowAnonymous Whether unauthenticated offers lacking `a=identity` are permitted.
	 * @param TokenVerifier|null $tokenVerifier Optional verifier for validating GameServerToken signatures against Auth Services.
	 */
	public function __construct(
		private readonly bool $allowAnonymous = false,
		private readonly ?TokenVerifier $tokenVerifier = null
	){}

	/**
	 * @throws IdentityException
	 */
	public function verify(?string $attributeValue, Fingerprint $remoteFingerprint) : ?VerifiedIdentity{
		if($attributeValue === null){
			if(!$this->allowAnonymous){
				throw new IdentityException("Offer carries no identity assertion");
			}

			return null;
		}

		try{
			$assertion = IdentityAssertion::parse($attributeValue);
			$token = $assertion->getToken();
			$token->checkTimestamps();
		}catch(CryptoException $e){
			throw new IdentityException("Identity assertion is malformed: " . $e->getMessage(), 0, $e);
		}

		$this->tokenVerifier?->check($token);

		try{
			$publicKey = $assertion->verifyFingerprint($remoteFingerprint);
		}catch(CryptoException $e){
			throw new IdentityException("Identity assertion does not cover this connection: " . $e->getMessage(), 0, $e);
		}

		return new VerifiedIdentity($publicKey, $token, $assertion->getDomain());
	}
}

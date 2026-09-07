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
 * Verifies a peer's identity assertion (`a=identity`) against its DTLS fingerprint.
 *
 * This confirms that the peer holds the private key matching the public key (`cpk`) in the token.
 * To validate the token signature or enforce custom policies, provide a {@link TokenVerifier}
 * (e.g. {@link SelfSignedTokenVerifier}).
 */
final class AssertionIdentityVerifier implements IdentityVerifier{

	/**
	 * @param bool               $allowAnonymous Whether offers without an `a=identity` assertion are allowed.
	 * @param TokenVerifier|null $tokenVerifier  Optional verifier to validate the token. If null,
	 *                                           any well-formed, unexpired token is accepted.
	 */
	public function __construct(
		private readonly bool $allowAnonymous = false,
		private readonly ?TokenVerifier $tokenVerifier = null
	){}

	/**
	 * @throws IdentityException
	 */
	public function verify(?string $attributeValue, Fingerprint $remoteFingerprint) : ?PeerIdentity{
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

		return new PeerIdentity($publicKey, $assertion->getDomain());
	}
}

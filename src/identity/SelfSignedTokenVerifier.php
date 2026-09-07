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

/**
 * Verifies that a peer's token is signed by its own public key (`cpk` claim).
 *
 * Suitable for hosts advertising {@link \pocketmine\nethernet\discovery\ServerData::$acceptsSelfSignedAuth},
 * where peers generate their own tokens and are identified by public key rather than by account.
 *
 * This does not authenticate the player with Minecraft Auth Services.
 */
final class SelfSignedTokenVerifier implements TokenVerifier{

	public function check(JsonWebToken $token) : void{
		try{
			$token->verifySelfSigned();
		}catch(CryptoException $e){
			throw new IdentityException("Token is not self-signed: " . $e->getMessage(), 0, $e);
		}
	}
}

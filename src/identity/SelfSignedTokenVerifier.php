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

final class SelfSignedTokenVerifier implements TokenVerifier{

	public function check(JsonWebToken $token) : void{
		try{
			$token->verifySelfSigned();
		}catch(CryptoException $e){
			throw new IdentityException("Token is not self-signed: " . $e->getMessage(), 0, $e);
		}
	}
}

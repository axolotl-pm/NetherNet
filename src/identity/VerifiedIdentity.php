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

/**
 * Holds verified cryptographic identity, `cpk` public key, and token claims for an authenticated peer.
 */
final class VerifiedIdentity{

	public function __construct(
		public readonly PublicKey $publicKey,
		public readonly JsonWebToken $token,
		public readonly string $providerDomain
	){}
}

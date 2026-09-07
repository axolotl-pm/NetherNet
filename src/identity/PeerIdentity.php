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
 * Represents the verified cryptographic identity of a connected peer.
 *
 * The public key is extracted from the peer's token (`cpk` claim) and verified against the connection's DTLS fingerprint.
 * This confirms possession of the matching private key.
 *
 * Note: Token claims (such as XUID) are not verified against Minecraft Auth Services here, as Mojang does not
 * publish signing keys for GameServerTokens. To bind a connection to an authenticated Minecraft player, verify
 * that the `cpk` in the Minecraft Login packet chain matches {@link self::$publicKey}.
 */
final class PeerIdentity{

	/**
	 * @param PublicKey $publicKey      The peer's verified public key, bound to this connection's DTLS fingerprint.
	 * @param string    $providerDomain Identity provider domain claimed in the assertion (unverified, for informational/display purposes only).
	 */
	public function __construct(
		public readonly PublicKey $publicKey,
		public readonly string $providerDomain
	){}
}

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

use pocketmine\nethernet\sdp\Fingerprint;

/**
 * Interface for verifying identity assertions presented by connecting peers.
 */
interface IdentityVerifier{

	/**
	 * @param string|null $attributeValue    The offer's `a=identity` value, or null if omitted.
	 * @param Fingerprint $remoteFingerprint The offer's DTLS fingerprint to be verified against the assertion.
	 *
	 * @return PeerIdentity|null Returns null if peers without an assertion are permitted.
	 *
	 * @throws IdentityException if the identity assertion is invalid or rejected.
	 */
	public function verify(?string $attributeValue, Fingerprint $remoteFingerprint) : ?PeerIdentity;
}

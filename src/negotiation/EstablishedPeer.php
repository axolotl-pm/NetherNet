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

namespace pocketmine\nethernet\negotiation;

use pmmp\webrtc\DataChannel;
use pmmp\webrtc\PeerConnection;
use pocketmine\nethernet\identity\VerifiedIdentity;
use pocketmine\nethernet\session\Reliability;

/**
 * Represents an established WebRTC peer connection ready to be converted into an active session.
 */
final class EstablishedPeer{

	/**
	 * @param DataChannel[] $channels Keyed by {@link Reliability} case name.
	 * @phpstan-param array<string, DataChannel> $channels
	 */
	public function __construct(
		public readonly PeerConnection $peerConnection,
		public readonly array $channels,
		public readonly string $networkId,
		public readonly ?VerifiedIdentity $identity
	){}
}

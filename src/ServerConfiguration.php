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

namespace pocketmine\nethernet;

use pocketmine\nethernet\identity\AssertionIdentityVerifier;
use pocketmine\nethernet\identity\IdentityProvider;
use pocketmine\nethernet\identity\IdentityVerifier;
use pocketmine\nethernet\negotiation\ConfiguredPeerConnectionFactory;
use pocketmine\nethernet\negotiation\PeerConnectionFactory;
use pocketmine\nethernet\negotiation\WebRtcNegotiator;

/**
 * Configuration parameters and dependencies for initializing a NetherNet server host.
 */
final class ServerConfiguration{

	/**
	 * @param IdentityProvider              $identityProvider      Issues server identity assertions (`a=identity`) for SDP answers.
	 * @param IdentityVerifier              $identityVerifier      Verifies connecting client identity assertions and GameServerTokens.
	 * @param PeerConnectionFactory         $peerConnectionFactory Factory for creating WebRTC PeerConnection instances.
	 * @param ConnectionBudgetConfiguration $budget                Resource limits and queue thresholds for peer connections.
	 * @param float                         $gatheringTimeout      Timeout in seconds for ICE candidate gathering.
	 * @param float                         $channelTimeout        Timeout in seconds for data channels to open.
	 * @param int                           $maxRemoteCandidates   Maximum ICE candidates a peer may offer or trickle per connection.
	 */
	public function __construct(
		public readonly IdentityProvider $identityProvider,
		public readonly IdentityVerifier $identityVerifier = new AssertionIdentityVerifier(),
		public readonly PeerConnectionFactory $peerConnectionFactory = new ConfiguredPeerConnectionFactory(),
		public readonly ConnectionBudgetConfiguration $budget = new ConnectionBudgetConfiguration(),
		public readonly float $gatheringTimeout = 15.0,
		public readonly float $channelTimeout = 5.0,
		public readonly int $maxRemoteCandidates = WebRtcNegotiator::DEFAULT_MAX_REMOTE_CANDIDATES,
		public readonly ?\Logger $logger = null
	){
		if($maxRemoteCandidates < 1){
			throw new \InvalidArgumentException("Maximum remote candidates must be positive, got $maxRemoteCandidates");
		}
		if($gatheringTimeout <= 0.0 || $channelTimeout <= 0.0){
			throw new \InvalidArgumentException("Timeouts must be positive");
		}
	}
}

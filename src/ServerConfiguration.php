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
use pocketmine\nethernet\session\framing\Segmenter;
use pocketmine\nethernet\session\Session;

/**
 * Configuration parameters and dependencies for initializing a NetherNet server host.
 */
final class ServerConfiguration{

	/**
	 * Default maximum SCTP message size (256 KB) advertised in SDP answer descriptions (`a=max-message-size:262144`).
	 */
	public const DEFAULT_MAX_MESSAGE_SIZE = Segmenter::MAX_SEGMENT_PAYLOAD_SIZE + 1;

	/**
	 * @param IdentityProvider      $identityProvider        Issues server identity assertions (`a=identity`) for SDP answers.
	 * @param IdentityVerifier      $identityVerifier        Verifies connecting client identity assertions and GameServerTokens.
	 * @param PeerConnectionFactory $peerConnectionFactory   Factory for creating WebRTC PeerConnection instances.
	 * @param float                 $gatheringTimeout        Timeout in seconds for ICE candidate gathering.
	 * @param float                 $channelTimeout          Timeout in seconds for data channels to open.
	 * @param int                   $maxMessageSize          Maximum SCTP message size in bytes.
	 * @param int                   $maxPayloadSize          Maximum reassembled message payload size in bytes.
	 * @param int                   $maxReceiveQueueSize     Maximum unread incoming bytes before dropping a session.
	 * @param int                   $maxReceiveQueueMessages Maximum unread incoming messages before dropping a session.
	 * @param int                   $maxSendQueueSize        Maximum queued outgoing bytes before dropping a session.
	 */
	public function __construct(
		public readonly IdentityProvider $identityProvider,
		public readonly IdentityVerifier $identityVerifier = new AssertionIdentityVerifier(),
		public readonly PeerConnectionFactory $peerConnectionFactory = new ConfiguredPeerConnectionFactory(),
		public readonly float $gatheringTimeout = 15.0,
		public readonly float $channelTimeout = 5.0,
		public readonly int $maxMessageSize = self::DEFAULT_MAX_MESSAGE_SIZE,
		public readonly int $maxPayloadSize = Segmenter::MAX_PAYLOAD_SIZE,
		public readonly int $maxReceiveQueueSize = Session::DEFAULT_MAX_RECEIVE_QUEUE_SIZE,
		public readonly int $maxReceiveQueueMessages = Session::DEFAULT_MAX_RECEIVE_QUEUE_MESSAGES,
		public readonly int $maxSendQueueSize = Session::DEFAULT_MAX_SEND_QUEUE_SIZE,
		public readonly ?\Logger $logger = null
	){
		if($maxMessageSize < 2 || $maxMessageSize > self::DEFAULT_MAX_MESSAGE_SIZE){
			throw new \InvalidArgumentException("Maximum message size must be between 2 and " . self::DEFAULT_MAX_MESSAGE_SIZE . ", got $maxMessageSize");
		}
		if($maxPayloadSize < 1){
			throw new \InvalidArgumentException("Maximum payload size must be positive, got $maxPayloadSize");
		}
		if($maxReceiveQueueSize < 1){
			throw new \InvalidArgumentException("Maximum receive queue size must be positive, got $maxReceiveQueueSize");
		}
		if($maxReceiveQueueMessages < 1){
			throw new \InvalidArgumentException("Maximum receive queue message count must be positive, got $maxReceiveQueueMessages");
		}
		if($maxSendQueueSize < 1){
			throw new \InvalidArgumentException("Maximum send queue size must be positive, got $maxSendQueueSize");
		}
		if($gatheringTimeout <= 0.0 || $channelTimeout <= 0.0){
			throw new \InvalidArgumentException("Timeouts must be positive");
		}
	}

	public function createSegmenter() : Segmenter{
		return new Segmenter($this->maxMessageSize - 1);
	}
}

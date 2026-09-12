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

use pocketmine\nethernet\session\framing\Segmenter;

/**
 * Resource limits and queue thresholds for a single peer connection.
 */
final class ConnectionBudgetConfiguration{

	/**
	 * Default maximum SCTP message size advertised in SDP answers (`a=max-message-size`).
	 */
	public const DEFAULT_MAX_MESSAGE_SIZE = Segmenter::MAX_SEGMENT_PAYLOAD_SIZE + 1;

	/**
	 * Default maximum reassembled message payload size in bytes.
	 */
	public const DEFAULT_MAX_PAYLOAD_SIZE = 262144;

	/**
	 * Default maximum unread incoming bytes before dropping a session.
	 */
	public const DEFAULT_MAX_RECEIVE_QUEUE_SIZE = 262144;

	/**
	 * Default maximum unread incoming messages before dropping a session.
	 */
	public const DEFAULT_MAX_RECEIVE_QUEUE_MESSAGES = 256;

	/**
	 * Default maximum queued outgoing bytes before dropping a session.
	 */
	public const DEFAULT_MAX_SEND_QUEUE_SIZE = 4194304;

	/**
	 * Default maximum pending data channels per connection. A peer requires 2 data channels; extra
	 * headroom is provided because the native extension tracks table entries rather than active channels.
	 */
	public const DEFAULT_MAX_PENDING_DATA_CHANNELS = 4;

	/**
	 * Multiplier applied to receive queue thresholds to calculate native limits.
	 */
	public const DEFAULT_NATIVE_MARGIN = 2;

	/**
	 * @param int $maxMessageSize          Maximum SCTP message size in bytes.
	 * @param int $maxPayloadSize          Maximum reassembled message payload size in bytes.
	 * @param int $maxReceiveQueueSize     Maximum unread incoming bytes before dropping a session.
	 * @param int $maxReceiveQueueMessages Maximum unread incoming messages before dropping a session.
	 * @param int $maxSendQueueSize        Maximum queued outgoing bytes before dropping a session.
	 * @param int $maxPendingDataChannels  Maximum pending data channels permitted per connection.
	 * @param int $nativeMargin            Multiplier applied to receive queue thresholds to calculate native limits.
	 */
	public function __construct(
		public readonly int $maxMessageSize = self::DEFAULT_MAX_MESSAGE_SIZE,
		public readonly int $maxPayloadSize = self::DEFAULT_MAX_PAYLOAD_SIZE,
		public readonly int $maxReceiveQueueSize = self::DEFAULT_MAX_RECEIVE_QUEUE_SIZE,
		public readonly int $maxReceiveQueueMessages = self::DEFAULT_MAX_RECEIVE_QUEUE_MESSAGES,
		public readonly int $maxSendQueueSize = self::DEFAULT_MAX_SEND_QUEUE_SIZE,
		public readonly int $maxPendingDataChannels = self::DEFAULT_MAX_PENDING_DATA_CHANNELS,
		public readonly int $nativeMargin = self::DEFAULT_NATIVE_MARGIN
	){
		if($maxMessageSize < 2 || $maxMessageSize > self::DEFAULT_MAX_MESSAGE_SIZE){
			throw new \InvalidArgumentException("Maximum message size must be between 2 and " . self::DEFAULT_MAX_MESSAGE_SIZE . ", got $maxMessageSize");
		}
		if($maxPayloadSize < $maxMessageSize - 1 || $maxPayloadSize > Segmenter::MAX_PAYLOAD_SIZE){
			throw new \InvalidArgumentException("Maximum payload size must be between " . ($maxMessageSize - 1) . " and " . Segmenter::MAX_PAYLOAD_SIZE . ", got $maxPayloadSize");
		}
		if($maxReceiveQueueSize < $maxMessageSize){
			throw new \InvalidArgumentException("Maximum receive queue size must be at least one message ($maxMessageSize), got $maxReceiveQueueSize");
		}
		if($maxReceiveQueueMessages < 1){
			throw new \InvalidArgumentException("Maximum receive queue message count must be positive, got $maxReceiveQueueMessages");
		}
		if($maxSendQueueSize < $maxMessageSize){
			throw new \InvalidArgumentException("Maximum send queue size must be at least one message ($maxMessageSize), got $maxSendQueueSize");
		}
		if($maxPendingDataChannels < 2){
			throw new \InvalidArgumentException("Maximum pending data channels must be at least 2, got $maxPendingDataChannels");
		}
		if($nativeMargin < 2){
			throw new \InvalidArgumentException("Native margin must be at least 2, got $nativeMargin");
		}
	}

	public function getNativeReceiveQueueSize() : int{
		return $this->maxReceiveQueueSize * $this->nativeMargin;
	}

	public function getNativeReceiveQueueMessages() : int{
		return $this->maxReceiveQueueMessages * $this->nativeMargin;
	}

	/**
	 * Returns the unscaled send queue threshold, as native limits are enforced per data channel
	 * while sessions enforce the threshold across all channels combined.
	 */
	public function getNativeSendQueueSize() : int{
		return $this->maxSendQueueSize;
	}

	public function createSegmenter() : Segmenter{
		return new Segmenter($this->maxMessageSize - 1);
	}
}

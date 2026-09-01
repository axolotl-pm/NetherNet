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

namespace pocketmine\nethernet\session;

use pmmp\webrtc\DataChannel;

/**
 * Represents the two SCTP data channels used by NetherNet: `ReliableDataChannel` and `UnreliableDataChannel`.
 */
enum Reliability{

	/**
	 * Ordered, lossless SCTP data channel with fragmentation support for game data.
	 */
	case RELIABLE;

	/**
	 * Unordered, loss-tolerant SCTP data channel (maxRetransmits=0, no fragmentation) for latency-sensitive data.
	 */
	case UNRELIABLE;

	public function getChannelLabel() : string{
		return match($this){
			self::RELIABLE => "ReliableDataChannel",
			self::UNRELIABLE => "UnreliableDataChannel"
		};
	}

	/**
	 * Returns whether multi-segment message fragmentation is supported on this channel (ReliableDataChannel only).
	 */
	public function isFragmentationSupported() : bool{
		return $this === self::RELIABLE;
	}

	public static function fromChannelLabel(string $label) : ?self{
		foreach(self::cases() as $case){
			if($case->getChannelLabel() === $label){
				return $case;
			}
		}

		return null;
	}

	/**
	 * Verifies that a remote data channel's configuration matches the NetherNet protocol specification.
	 */
	public function matches(DataChannel $channel) : bool{
		if($channel->getLabel() !== $this->getChannelLabel() || $channel->getProtocol() !== ""){
			return false;
		}
		if($channel->getMaxPacketLifeTime() !== null){
			return false;
		}

		return match($this){
			self::RELIABLE => !$channel->isUnordered() && $channel->getMaxRetransmits() === null,
			self::UNRELIABLE => $channel->isUnordered() && $channel->getMaxRetransmits() === 0
		};
	}
}

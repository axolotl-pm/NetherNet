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

namespace pocketmine\nethernet\discovery;

use function explode;

/**
 * Represents a text-based signaling message exchanged during discovery.
 */
final class Signal{

	public function __construct(
		public readonly SignalType $type,
		public readonly string $connectionId,
		public readonly string $data
	){}

	/**
	 * @throws DiscoveryException
	 */
	public static function parse(string $message) : self{
		$parts = explode(" ", $message, 3);
		if(!isset($parts[2])){
			throw new DiscoveryException("Signalling message must have at least three space-separated fields");
		}

		$type = SignalType::tryFrom($parts[0]);
		if($type === null){
			throw new DiscoveryException("Unknown signalling message type '$parts[0]'");
		}

		return new self($type, $parts[1], $parts[2]);
	}

	public function toString() : string{
		return $this->type->value . " " . $this->connectionId . " " . $this->data;
	}
}

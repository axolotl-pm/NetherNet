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

use pocketmine\nethernet\negotiation\Negotiation;

/**
 * Tracks an in-progress LAN Trickle ICE signaling negotiation.
 */
final class PendingConnection{

	public bool $answerSent = false;

	/**
	 * @param int $peerId Remote peer's 64-bit NetworkID.
	 */
	public function __construct(
		public readonly Negotiation $negotiation,
		public readonly int $peerId,
		public readonly string $connectionId
	){}
}

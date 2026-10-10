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

use pocketmine\nethernet\negotiation\Negotiator;
use pocketmine\nethernet\signaling\SignalingContext;
use pocketmine\nethernet\utils\AddressBlockTracker;

final class TestSignalingContext{

	private function __construct(){
		//NOOP
	}

	/**
	 * Builds what a server would hand a transport at startup, for tests that start one on its own.
	 */
	public static function create(Negotiator $negotiator, ?\Logger $logger = null) : SignalingContext{
		return new SignalingContext($negotiator, new AddressBlockTracker(), $logger ?? new RecordingLogger());
	}
}

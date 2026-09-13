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

use pocketmine\nethernet\session\DisconnectReason;
use pocketmine\nethernet\session\Reliability;
use pocketmine\nethernet\session\Session;

final class NullEventListener implements ServerEventListener{

	public function onSessionOpen(Session $session) : void{
		//NOOP
	}

	public function canAcceptPackets() : bool{
		return true;
	}

	public function onPacketReceive(Session $session, string $payload, Reliability $reliability) : void{
		//NOOP
	}

	public function onSessionClose(Session $session, DisconnectReason $reason) : void{
		//NOOP
	}
}

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

namespace pocketmine\nethernet\signaling\lan\packet;

enum PacketType : int{

	case REQUEST = 0;
	case RESPONSE = 1;
	case MESSAGE = 2;
	case RELIABLE = 3;
	case ACKNOWLEDGEMENT = 4;

	public function isReliable() : bool{
		return $this === self::RELIABLE || $this === self::ACKNOWLEDGEMENT;
	}
}

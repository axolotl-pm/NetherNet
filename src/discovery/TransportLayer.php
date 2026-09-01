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

/**
 * Transport layer type advertised to connecting clients.
 *
 * The values step by two rather than by one, because the game derives them by
 * shifting the ordinal left. Renumbering them consecutively would point clients
 * at a transport the host is not running.
 */
enum TransportLayer : int{

	case RAKNET = 0;
	case NETHERNET = 2;
	case DEFAULT = 4;
}

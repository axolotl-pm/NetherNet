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

use pocketmine\nethernet\session\Reliability;
use pocketmine\nethernet\session\Session;

/**
 * Listens for client connection, message, and disconnection events.
 */
interface ServerEventListener{

	/**
	 * Called when a new client connects and its session is ready.
	 */
	public function onSessionOpen(Session $session) : void;

	/**
	 * Called when a message is received from a connected client.
	 */
	public function onPacketReceive(Session $session, string $payload, Reliability $reliability) : void;

	/**
	 * Called when a client disconnects or the session is closed.
	 */
	public function onSessionClose(Session $session, ?string $reason) : void;
}

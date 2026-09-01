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

namespace pocketmine\nethernet\signaling;

/**
 * Transport for exchanging connection offers and answers (e.g. LAN broadcast or HTTP).
 */
interface SignalingInterface{

	/**
	 * Starts listening for incoming connection requests.
	 *
	 * @throws SignalingException
	 */
	public function start() : void;

	/**
	 * Processes pending connection requests and signals.
	 */
	public function tick() : void;

	/**
	 * Stops listening and closes active resources.
	 */
	public function shutdown() : void;
}

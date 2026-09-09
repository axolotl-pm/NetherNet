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

namespace pocketmine\nethernet\negotiation;

use pmmp\webrtc\PeerConnection;
use pmmp\webrtc\WebRtcException;
use pocketmine\nethernet\ConnectionBudgetConfiguration;

/**
 * Factory interface for creating configured WebRTC PeerConnection instances.
 */
interface PeerConnectionFactory{

	/**
	 * Creates a WebRTC PeerConnection configured with the given budget limits.
	 *
	 * @throws WebRtcException
	 */
	public function create(ConnectionBudgetConfiguration $budget) : PeerConnection;
}

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

namespace pocketmine\nethernet\signaling\http;

/**
 * Lifecycle stages of an inbound HTTP signaling connection.
 */
enum HttpConnectionState{

	/**
	 * Non-blocking TLS handshake in progress.
	 */
	case HANDSHAKE;

	case READING_HEAD;
	case READING_BODY;

	/**
	 * Awaiting SDP answer generation from the negotiator.
	 */
	case NEGOTIATING;

	case WRITING;
	case DONE;

	/**
	 * Returns true if the connection is still in the request-reception phase.
	 */
	public function isAwaitingRequest() : bool{
		return match($this){
			self::HANDSHAKE, self::READING_HEAD, self::READING_BODY => true,
			default => false
		};
	}
}

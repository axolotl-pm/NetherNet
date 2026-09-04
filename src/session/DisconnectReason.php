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

namespace pocketmine\nethernet\session;

enum DisconnectReason : int{

	/**
	 * The remote client closed the connection or data channels.
	 */
	case PEER_DISCONNECT = 0;

	/**
	 * The server closed the session.
	 */
	case SERVER_DISCONNECT = 1;

	/**
	 * The server is shutting down.
	 */
	case SERVER_SHUTDOWN = 2;

	/**
	 * The server rejected the incoming session.
	 */
	case REJECTED_BY_HOST = 3;

	/**
	 * The underlying WebRTC peer connection failed.
	 */
	case CONNECTION_FAILED = 4;

	/**
	 * An incoming message could not be parsed or framed.
	 */
	case BAD_DATA = 5;

	/**
	 * An outgoing message failed to send.
	 */
	case SEND_FAILED = 6;

	/**
	 * Incoming unread bytes exceeded the receive queue limit.
	 */
	case RECEIVE_QUEUE_TOO_MANY_BYTES = 7;

	/**
	 * Incoming unread messages exceeded the receive queue limit.
	 */
	case RECEIVE_QUEUE_TOO_MANY_MESSAGES = 8;

	/**
	 * Outgoing unsent bytes exceeded the send queue limit.
	 */
	case SEND_QUEUE_TOO_MANY_BYTES = 9;

	/**
	 * Returns a human-readable fallback description for logging.
	 */
	public function getMessage() : string{
		return match($this){
			self::PEER_DISCONNECT => "client disconnect",
			self::SERVER_DISCONNECT => "server disconnect",
			self::SERVER_SHUTDOWN => "server shutdown",
			self::REJECTED_BY_HOST => "rejected by the host",
			self::CONNECTION_FAILED => "connection failed",
			self::BAD_DATA => "bad data received",
			self::SEND_FAILED => "failed to send",
			self::RECEIVE_QUEUE_TOO_MANY_BYTES => "too many unread bytes queued",
			self::RECEIVE_QUEUE_TOO_MANY_MESSAGES => "too many unread messages queued",
			self::SEND_QUEUE_TOO_MANY_BYTES => "too many unsent bytes queued"
		};
	}
}

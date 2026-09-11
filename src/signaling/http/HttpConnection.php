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

use pocketmine\nethernet\negotiation\Negotiation;
use function str_contains;

/**
 * Tracks the state of an inbound HTTP/HTTPS signaling connection.
 *
 * @internal
 */
final class HttpConnection{

	public HttpConnectionState $state = HttpConnectionState::READING_HEAD;

	public string $input = "";
	public string $output = "";

	public ?HttpRequest $request = null;

	/** Whether plain HTTP was received on a TLS listener and requires a redirect. */
	public bool $redirectToTls = false;

	/**
	 * Whether the TLS handshake has started. Once started, incoming bytes belong to the handshake and
	 * must not be checked for plain HTTP.
	 */
	public bool $handshakeStarted = false;

	public int $bodyOffset = 0;
	public int $contentLength = 0;

	public ?Negotiation $negotiation = null;

	public float $negotiationDeadline = 0.0;
	public float $writeDeadline = 0.0;

	/**
	 * @param resource $stream      Accepted stream resource for TLS and I/O.
	 * @param \Socket  $socket      Underlying socket reference preventing premature descriptor closure.
	 * @param string   $peerAddress IP address of the peer, or an empty string if it could not be read.
	 */
	public function __construct(
		public readonly mixed $stream,
		public readonly \Socket $socket,
		public readonly string $peerAddress,
		public readonly int $peerPort,
		public float $headDeadline,
		public float $bodyDeadline
	){}

	public function peerName() : string{
		if($this->peerAddress === ""){
			return "unknown";
		}

		return (str_contains($this->peerAddress, ":") ? "[" . $this->peerAddress . "]" : $this->peerAddress) . ":" . $this->peerPort;
	}
}

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

/**
 * Manages WebRTC handshakes for incoming connection requests.
 */
interface Negotiator{

	/**
	 * Initiates a handshake for an incoming SDP connection offer.
	 *
	 * @param string        $networkId     Peer network identifier.
	 * @param CandidateMode $candidateMode Strategy for candidate delivery.
	 * @param string|null   $peerAddress   IP address the offer was received from, if the signaling transport
	 *                                     knows it. It is offered to the peer connection as a candidate so
	 *                                     that a peer behind NAT can be reached through a TURN relay.
	 *
	 * @throws NegotiationException if the offer cannot be accepted.
	 */
	public function beginNegotiation(string $offerSdp, string $networkId, CandidateMode $candidateMode = CandidateMode::BUNDLED, ?string $peerAddress = null) : Negotiation;

	public function tick() : void;

	/**
	 * Returns established connections ready to become active client sessions.
	 *
	 * @return EstablishedPeer[]
	 * @phpstan-return list<EstablishedPeer>
	 */
	public function takeEstablished() : array;

	/**
	 * Aborts all ongoing handshakes and releases resources.
	 */
	public function shutdown() : void;
}

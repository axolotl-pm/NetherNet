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
 * Handle representing an in-progress WebRTC peer connection negotiation.
 */
interface Negotiation{

	/**
	 * Returns the SDP answer to send back to the peer, or null if gathering/generation is pending.
	 */
	public function getAnswer() : ?string;

	public function isFinished() : bool;

	public function isFailed() : bool;

	public function getFailureReason() : ?string;

	public function getFailureCode() : ErrorCode;

	/**
	 * Adds a remote ICE candidate received via trickle signaling.
	 *
	 * @throws NegotiationException
	 */
	public function addRemoteCandidate(string $candidate) : void;

	/**
	 * Drains and returns newly gathered local ICE candidates.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function takeLocalCandidates() : array;

	/**
	 * Aborts the negotiation with a specified failure reason and code.
	 */
	public function fail(string $reason, ErrorCode $code = ErrorCode::GENERIC_FAILURE) : void;
}

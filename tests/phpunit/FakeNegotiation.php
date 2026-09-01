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

use pocketmine\nethernet\negotiation\ErrorCode;
use pocketmine\nethernet\negotiation\Negotiation;

/**
 * A negotiation whose answer, candidates and outcome are all decided up front.
 */
final class FakeNegotiation implements Negotiation{

	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public array $remoteCandidates = [];

	private bool $failed = false;
	private ?string $failureReason = null;
	private ErrorCode $failureCode = ErrorCode::NONE;

	/**
	 * @param string[] $localCandidates
	 * @phpstan-param list<string> $localCandidates
	 */
	public function __construct(
		private readonly ?string $answer,
		private array $localCandidates
	){}

	public function getAnswer() : ?string{ return $this->failed ? null : $this->answer; }

	public function isFinished() : bool{ return $this->failed; }

	public function isFailed() : bool{ return $this->failed; }

	public function getFailureReason() : ?string{ return $this->failureReason; }

	public function getFailureCode() : ErrorCode{ return $this->failureCode; }

	public function addRemoteCandidate(string $candidate) : void{
		$this->remoteCandidates[] = $candidate;
	}

	public function takeLocalCandidates() : array{
		$taken = $this->localCandidates;
		$this->localCandidates = [];

		return $taken;
	}

	public function fail(string $reason, ErrorCode $code = ErrorCode::GENERIC_FAILURE) : void{
		$this->failed = true;
		$this->failureReason = $reason;
		$this->failureCode = $code;
	}
}

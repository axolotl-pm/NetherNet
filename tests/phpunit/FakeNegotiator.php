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

use pocketmine\nethernet\negotiation\CandidateMode;
use pocketmine\nethernet\negotiation\ErrorCode;
use pocketmine\nethernet\negotiation\Negotiation;
use pocketmine\nethernet\negotiation\Negotiator;

/**
 * Stands in for the real negotiator so a signaling transport can be driven
 * without a WebRTC stack behind it.
 *
 * Transports are worth testing on their own: what they do with an answer, a
 * candidate and a failure is their logic, not the negotiator's.
 */
final class FakeNegotiator implements Negotiator{

	public ?FakeNegotiation $negotiation = null;
	public ?string $lastOffer = null;
	public ?string $lastNetworkId = null;
	public ?CandidateMode $lastMode = null;
	public ?string $lastPeerAddress = null;

	/**
	 * @param string[] $localCandidates
	 * @phpstan-param list<string> $localCandidates
	 */
	public function __construct(
		private readonly ?string $answer,
		private readonly array $localCandidates = [],
		private readonly ?ErrorCode $failWith = null
	){}

	public function beginNegotiation(string $offerSdp, string $networkId, CandidateMode $candidateMode = CandidateMode::BUNDLED, ?string $peerAddress = null) : Negotiation{
		$this->lastOffer = $offerSdp;
		$this->lastNetworkId = $networkId;
		$this->lastMode = $candidateMode;
		$this->lastPeerAddress = $peerAddress;

		$negotiation = new FakeNegotiation($this->answer, $this->localCandidates);
		if($this->failWith !== null){
			$negotiation->fail("rejected by the test", $this->failWith);
		}

		return $this->negotiation = $negotiation;
	}

	public function tick() : void{
		//NOOP
	}

	public function takeEstablished() : array{
		return [];
	}

	public function shutdown() : void{
		//NOOP
	}
}

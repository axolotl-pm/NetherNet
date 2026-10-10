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
use pocketmine\nethernet\negotiation\EstablishedPeer;
use pocketmine\nethernet\negotiation\Negotiation;
use pocketmine\nethernet\negotiation\Negotiator;
use pocketmine\nethernet\protocol\ErrorCode;

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

	/** @phpstan-var list<EstablishedPeer> */
	public array $established = [];
	/** @phpstan-var list<float> */
	public array $ticks = [];
	public bool $shutDown = false;

	/**
	 * @param string[] $localCandidates
	 * @phpstan-param list<string> $localCandidates
	 */
	public function __construct(
		private readonly ?string $answer = null,
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

	public function tick(float $now) : void{
		$this->ticks[] = $now;
	}

	public function takeEstablished() : array{
		$taken = $this->established;
		$this->established = [];

		return $taken;
	}

	public function shutdown() : void{
		$this->shutDown = true;
	}
}

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

use pocketmine\nethernet\transport\MessageChannel;
use pocketmine\nethernet\transport\PeerTransport;
use pocketmine\nethernet\transport\TransportException;
use pocketmine\nethernet\transport\TransportState;
use pocketmine\nethernet\utils\InternetAddress;

final class FakePeerTransport implements PeerTransport{

	public TransportState $state = TransportState::CONNECTING;
	public bool $lostConnectivity = false;

	public ?string $remoteOffer = null;
	public bool $rejectOffer = false;

	public ?string $localDescription = null;
	public bool $gatheringComplete = false;

	/** @phpstan-var list<string> */
	public array $remoteCandidates = [];
	public bool $rejectCandidates = false;

	/** @phpstan-var list<string> */
	public array $localCandidates = [];

	/** @phpstan-var list<MessageChannel> */
	public array $pendingChannels = [];

	public ?InternetAddress $remoteAddress = null;
	public ?int $roundTripTime = null;
	public int $bytesSent = 0;
	public int $bytesReceived = 0;

	public bool $closed = false;

	public function setRemoteOffer(string $sdp) : void{
		if($this->rejectOffer){
			throw new TransportException("offer rejected by the test");
		}
		$this->remoteOffer = $sdp;
	}

	public function getLocalDescription() : ?string{ return $this->localDescription; }

	public function isGatheringComplete() : bool{ return $this->gatheringComplete; }

	public function addRemoteCandidate(string $candidate) : void{
		if($this->rejectCandidates){
			throw new TransportException("candidate rejected by the test");
		}
		$this->remoteCandidates[] = $candidate;
	}

	public function pollLocalCandidates() : array{
		$taken = $this->localCandidates;
		$this->localCandidates = [];

		return $taken;
	}

	public function pollChannels() : array{
		$taken = $this->pendingChannels;
		$this->pendingChannels = [];

		return $taken;
	}

	public function getState() : TransportState{
		return $this->closed ? TransportState::CLOSED : $this->state;
	}

	public function hasLostConnectivity() : bool{ return $this->lostConnectivity; }

	public function getRemoteAddress() : ?InternetAddress{ return $this->remoteAddress; }

	public function getRoundTripTime() : ?int{ return $this->closed ? null : $this->roundTripTime; }

	public function getBytesSent() : int{ return $this->bytesSent; }

	public function getBytesReceived() : int{ return $this->bytesReceived; }

	public function close() : void{
		$this->closed = true;
	}
}

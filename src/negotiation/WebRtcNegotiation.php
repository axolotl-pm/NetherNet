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

use pmmp\webrtc\DataChannel;
use pmmp\webrtc\IceCandidate;
use pmmp\webrtc\PeerConnection;
use pmmp\webrtc\WebRtcException;
use pocketmine\nethernet\identity\PeerIdentity;
use pocketmine\nethernet\session\Reliability;

/**
 * Tracks state, SDP answer delivery, and ICE candidate exchange for an active WebRTC negotiation.
 */
final class WebRtcNegotiation implements Negotiation{

	private NegotiationState $state = NegotiationState::GATHERING;
	private ?string $answer = null;
	private ?string $failureReason = null;
	private ErrorCode $failureCode = ErrorCode::NONE;

	private ?string $localUfrag = null;
	private int $candidateIndex = 0;
	private int $remoteCandidateCount = 0;

	/**
	 * @var DataChannel[]
	 * @phpstan-var array<string, DataChannel>
	 */
	private array $channels = [];

	/** @internal */
	public function __construct(
		private readonly PeerConnection $peerConnection,
		private readonly string $networkId,
		private readonly ?PeerIdentity $identity,
		private readonly CandidateMode $candidateMode,
		private float $deadline,
		private readonly int $maxRemoteCandidates
	){}

	public function getAnswer() : ?string{ return $this->answer; }

	public function isFinished() : bool{ return $this->state->isFinished(); }

	public function isFailed() : bool{
		return match($this->state){
			NegotiationState::FAILED => true,
			default => false
		};
	}

	public function getFailureReason() : ?string{ return $this->failureReason; }

	public function getFailureCode() : ErrorCode{ return $this->failureCode; }

	public function addRemoteCandidate(string $candidate) : void{
		if($this->state->isFinished()){
			return;
		}
		if($this->remoteCandidateCount >= $this->maxRemoteCandidates){
			return;
		}
		$this->remoteCandidateCount++;

		try{
			$this->peerConnection->addRemoteCandidate(IceCandidate::create($candidate));
		}catch(WebRtcException $e){
			throw new NegotiationException("Peer sent an unusable candidate: " . $e->getMessage(), ErrorCode::CANDIDATE_ADD, $e);
		}
	}

	public function takeLocalCandidates() : array{
		$ufrag = $this->localUfrag;
		if($ufrag === null || $this->state->isFinished()){
			return [];
		}
		if($this->candidateMode === CandidateMode::BUNDLED){
			return [];
		}

		try{
			$gathered = $this->peerConnection->pollLocalCandidates();
		}catch(WebRtcException){
			return [];
		}

		$formatted = [];
		foreach($gathered as $candidate){
			try{
				$formatted[] = IceCandidateFormatter::format($candidate->getCandidate(), $ufrag, $this->candidateIndex++);
			}catch(NegotiationException){
				continue;
			}
		}

		return $formatted;
	}

	public function fail(string $reason, ErrorCode $code = ErrorCode::GENERIC_FAILURE) : void{
		if($this->state->isFinished()){
			return;
		}

		$this->state = NegotiationState::FAILED;
		$this->failureReason = $reason;
		$this->failureCode = $code;

		try{
			$this->peerConnection->close();
		}catch(WebRtcException){
		}
	}

	public function getState() : NegotiationState{ return $this->state; }

	public function getNetworkId() : string{ return $this->networkId; }

	public function getCandidateMode() : CandidateMode{ return $this->candidateMode; }

	/** @internal */
	public function getPeerConnection() : PeerConnection{ return $this->peerConnection; }

	/** @internal */
	public function getDeadline() : float{ return $this->deadline; }

	/**
	 * @internal
	 *
	 * @return DataChannel[]
	 * @phpstan-return array<string, DataChannel>
	 */
	public function getChannels() : array{ return $this->channels; }

	/** @internal */
	public function hasChannel(Reliability $reliability) : bool{
		return isset($this->channels[$reliability->name]);
	}

	/** @internal */
	public function addChannel(Reliability $reliability, DataChannel $channel) : void{
		$this->channels[$reliability->name] = $channel;
	}

	/** @internal */
	public function setAnswered(string $answer, string $localUfrag, float $deadline) : void{
		$this->answer = $answer;
		$this->localUfrag = $localUfrag;
		$this->deadline = $deadline;
		$this->state = NegotiationState::ANSWERED;
	}

	/**
	 * @internal
	 */
	public function establish() : EstablishedPeer{
		$this->state = NegotiationState::ESTABLISHED;

		return new EstablishedPeer($this->peerConnection, $this->channels, $this->networkId, $this->identity);
	}
}

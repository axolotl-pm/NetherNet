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

use pmmp\webrtc\ConnectionState;
use pmmp\webrtc\GatheringState;
use pmmp\webrtc\PeerConnection;
use pmmp\webrtc\WebRtcException;
use pocketmine\nethernet\ConnectionBudgetConfiguration;
use pocketmine\nethernet\crypto\CryptoException;
use pocketmine\nethernet\identity\IdentityException;
use pocketmine\nethernet\identity\IdentityProvider;
use pocketmine\nethernet\identity\IdentityVerifier;
use pocketmine\nethernet\sdp\SdpException;
use pocketmine\nethernet\sdp\SessionDescription;
use pocketmine\nethernet\session\Reliability;
use function microtime;

final class WebRtcNegotiator implements Negotiator{

	public const DEFAULT_MAX_REMOTE_CANDIDATES = 32;

	/**
	 * @var WebRtcNegotiation[]
	 * @phpstan-var array<int, WebRtcNegotiation>
	 */
	private array $negotiations = [];

	/**
	 * @var EstablishedPeer[]
	 * @phpstan-var list<EstablishedPeer>
	 */
	private array $established = [];

	private int $nextNegotiationId = 0;
	private bool $closed = false;

	public function __construct(
		private readonly IdentityProvider $identityProvider,
		private readonly IdentityVerifier $identityVerifier,
		private readonly PeerConnectionFactory $peerConnectionFactory,
		private readonly ConnectionBudgetConfiguration $budget,
		private readonly float $gatheringTimeout,
		private readonly float $channelTimeout,
		private readonly int $maxRemoteCandidates,
		private readonly ?\Logger $logger = null
	){
		if($gatheringTimeout <= 0.0 || $channelTimeout <= 0.0){
			throw new \InvalidArgumentException("Timeouts must be positive");
		}
		if($maxRemoteCandidates < 1){
			throw new \InvalidArgumentException("Maximum remote candidates must be positive, got $maxRemoteCandidates");
		}
	}

	public function beginNegotiation(string $offerSdp, string $networkId, CandidateMode $candidateMode = CandidateMode::BUNDLED, ?string $peerAddress = null) : Negotiation{
		if($this->closed){
			throw new NegotiationException("Negotiator is shut down", ErrorCode::NO_SIGNALING_CHANNEL);
		}

		try{
			$offer = SessionDescription::parse($offerSdp);
			$offer->validate();
			$fingerprint = $offer->getFingerprint();
		}catch(SdpException $e){
			throw new NegotiationException("Offer is invalid: " . $e->getMessage(), ErrorCode::FAILED_TO_SET_REMOTE_DESCRIPTION, $e);
		}

		$candidateCount = $offer->countCandidates();
		if($candidateCount > $this->maxRemoteCandidates){
			throw new NegotiationException("Offer carries $candidateCount ICE candidates, over the limit of " . $this->maxRemoteCandidates, ErrorCode::CANDIDATE_ADD);
		}

		try{
			$identity = $this->identityVerifier->verify($offer->getIdentity(), $fingerprint);
		}catch(IdentityException $e){
			throw new NegotiationException("Identity rejected: " . $e->getMessage(), ErrorCode::IDENTITY_NOT_ALLOWED, $e);
		}

		try{
			$peerConnection = $this->peerConnectionFactory->create($this->budget);
		}catch(WebRtcException $e){
			throw new NegotiationException("Could not create a peer connection: " . $e->getMessage(), ErrorCode::FAILED_TO_CREATE_PEER_CONNECTION, $e);
		}

		try{
			$peerConnection->setRemoteOffer($offer->withoutIdentity()->toString());
		}catch(WebRtcException $e){
			$this->discard($peerConnection);

			throw new NegotiationException("Offer was rejected by the WebRTC stack: " . $e->getMessage(), ErrorCode::FAILED_TO_SET_REMOTE_DESCRIPTION, $e);
		}

		$negotiation = new WebRtcNegotiation(
			$peerConnection,
			$networkId,
			$identity,
			$candidateMode,
			microtime(true) + $this->gatheringTimeout,
			$this->maxRemoteCandidates
		);
		$this->negotiations[$this->nextNegotiationId++] = $negotiation;

		if($peerAddress !== null){
			$this->addSignalingPeerCandidate($negotiation, $offer, $peerAddress);
		}

		return $negotiation;
	}

	private function addSignalingPeerCandidate(WebRtcNegotiation $negotiation, SessionDescription $offer, string $peerAddress) : void{
		$candidate = IceCandidateFormatter::signalingPeer($offer, $peerAddress);
		if($candidate === null){
			return;
		}

		try{
			$negotiation->addRemoteCandidate($candidate);
		}catch(NegotiationException $e){
			$this->logger?->debug("Signaling peer candidate for " . $negotiation->getNetworkId() . " was rejected: " . $e->getMessage());
		}
	}

	public function tick() : void{
		if($this->closed){
			return;
		}

		$now = microtime(true);
		foreach($this->negotiations as $id => $negotiation){
			try{
				$this->advance($negotiation, $now);
			}catch(WebRtcException $e){
				$negotiation->fail("WebRTC stack failed: " . $e->getMessage(), ErrorCode::FAILED_TO_CREATE_PEER_CONNECTION);
			}

			if($negotiation->isFinished()){
				if($negotiation->isFailed()){
					$this->logger?->debug("Negotiation for " . $negotiation->getNetworkId() . " failed: " . ($negotiation->getFailureReason() ?? "no reason given"));
				}
				unset($this->negotiations[$id]);
			}
		}
	}

	public function takeEstablished() : array{
		$taken = $this->established;
		$this->established = [];

		return $taken;
	}

	public function shutdown() : void{
		if($this->closed){
			return;
		}
		$this->closed = true;

		foreach($this->negotiations as $negotiation){
			$negotiation->fail("Negotiator is shutting down", ErrorCode::NO_SIGNALING_CHANNEL);
		}
		$this->negotiations = [];

		foreach($this->established as $peer){
			$this->discard($peer->peerConnection);
		}
		$this->established = [];
	}

	/**
	 * @throws WebRtcException
	 */
	private function advance(WebRtcNegotiation $negotiation, float $now) : void{
		$state = $negotiation->getPeerConnection()->getState();
		$broken = match($state){
			ConnectionState::FAILED, ConnectionState::CLOSED => true,
			default => false
		};
		if($broken){
			$negotiation->fail("Peer connection entered state " . $state->name, ErrorCode::ICE);

			return;
		}

		match($negotiation->getState()){
			NegotiationState::GATHERING => $this->advanceGathering($negotiation, $now),
			NegotiationState::ANSWERED => $this->advanceAnswered($negotiation, $now),
			default => null
		};
	}

	/**
	 * @throws WebRtcException
	 */
	private function advanceGathering(WebRtcNegotiation $negotiation, float $now) : void{
		$peerConnection = $negotiation->getPeerConnection();

		if($negotiation->getCandidateMode() === CandidateMode::BUNDLED){
			$complete = match($peerConnection->getGatheringState()){
				GatheringState::COMPLETE => true,
				default => false
			};
			if(!$complete){
				$this->checkDeadline($negotiation, $now, "Timed out gathering ICE candidates", ErrorCode::NEGOTIATION_TIMEOUT);

				return;
			}
		}

		try{
			$answer = $this->buildAnswer($peerConnection, $negotiation->getCandidateMode());
		}catch(CryptoException|SdpException $e){
			$negotiation->fail("Could not build an answer: " . $e->getMessage(), ErrorCode::FAILED_TO_CREATE_ANSWER);

			return;
		}
		if($answer === null){
			$this->checkDeadline($negotiation, $now, "Timed out waiting for a local description", ErrorCode::FAILED_TO_CREATE_ANSWER);

			return;
		}

		$negotiation->setAnswered($answer[0], $answer[1], $now + $this->channelTimeout);
	}

	/**
	 * @throws WebRtcException
	 */
	private function advanceAnswered(WebRtcNegotiation $negotiation, float $now) : void{
		$this->collectChannels($negotiation);
		if($negotiation->isFailed()){
			return;
		}

		$channels = $negotiation->getChannels();
		foreach(Reliability::cases() as $reliability){
			$channel = $channels[$reliability->name] ?? null;
			if($channel === null || !$channel->isOpen()){
				$this->checkDeadline($negotiation, $now, "Peer did not open both data channels", ErrorCode::NEGOTIATION_TIMEOUT_WAITING_FOR_ACCEPT);

				return;
			}
		}

		$this->established[] = $negotiation->establish();
	}

	/**
	 * Drains and records newly opened data channels on the peer connection.
	 *
	 * @throws WebRtcException
	 */
	private function collectChannels(WebRtcNegotiation $negotiation) : void{
		foreach($negotiation->getPeerConnection()->pollDataChannels() as $channel){
			$matched = null;
			foreach(Reliability::cases() as $reliability){
				if($reliability->matches($channel)){
					$matched = $reliability;
					break;
				}
			}

			if($matched === null){
				$negotiation->fail("Peer opened a channel this protocol does not define: " . $channel->getLabel(), ErrorCode::DATA_CHANNEL_CLOSED);

				return;
			}
			if($negotiation->hasChannel($matched)){
				$negotiation->fail("Peer opened the " . $matched->getChannelLabel() . " twice", ErrorCode::DATA_CHANNEL_CLOSED);

				return;
			}

			$negotiation->addChannel($matched, $channel);
		}
	}

	/**
	 * @phpstan-return array{string, string}|null
	 *
	 * @throws CryptoException
	 * @throws SdpException
	 * @throws WebRtcException
	 */
	private function buildAnswer(PeerConnection $peerConnection, CandidateMode $candidateMode) : ?array{
		$sdp = $peerConnection->getLocalDescription();
		if($sdp === null){
			return null;
		}

		$answer = SessionDescription::parse($sdp);
		$ufrag = $answer->getMediaAttributeValues("ice-ufrag")[0] ?? null;
		if($ufrag === null){
			throw new SdpException("Local description has no ice-ufrag");
		}

		if($candidateMode === CandidateMode::TRICKLE){
			$answer = $answer->withoutCandidates();
		}

		$assertion = $this->identityProvider->issue($answer->getFingerprint());

		return [$answer->withIdentity($assertion->encode())->toString(), $ufrag];
	}

	private function checkDeadline(WebRtcNegotiation $negotiation, float $now, string $reason, ErrorCode $code) : void{
		if($now >= $negotiation->getDeadline()){
			$negotiation->fail($reason, $code);
		}
	}

	private function discard(PeerConnection $peerConnection) : void{
		try{
			$peerConnection->close();
		}catch(WebRtcException){
		}
	}
}

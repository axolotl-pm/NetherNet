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

use pocketmine\nethernet\identity\AssertionIdentityVerifier;
use pocketmine\nethernet\identity\IdentityException;
use pocketmine\nethernet\identity\IdentityProvider;
use pocketmine\nethernet\protocol\ErrorCode;
use pocketmine\nethernet\sdp\SdpException;
use pocketmine\nethernet\sdp\SessionDescription;
use pocketmine\nethernet\transport\ConnectionBudgetConfiguration;
use pocketmine\nethernet\transport\PeerTransportFactory;
use pocketmine\nethernet\transport\TransportException;

final class TransportNegotiator implements Negotiator{

	public const DEFAULT_MAX_REMOTE_CANDIDATES = 32;

	/**
	 * @var TransportNegotiation[]
	 * @phpstan-var array<int, TransportNegotiation>
	 */
	private array $negotiations = [];

	/**
	 * @var EstablishedPeer[]
	 * @phpstan-var list<EstablishedPeer>
	 */
	private array $established = [];

	private int $nextNegotiationId = 0;
	private bool $shutDown = false;

	private readonly AnswerBuilder $answerBuilder;

	/**
	 * @param string[]|null $advertisedAddresses
	 * @phpstan-param list<string>|null $advertisedAddresses
	 */
	public function __construct(
		IdentityProvider $identityProvider,
		private readonly AssertionIdentityVerifier $identityVerifier,
		private readonly PeerTransportFactory $transportFactory,
		private readonly ConnectionBudgetConfiguration $budget,
		private readonly float $gatheringTimeout,
		private readonly float $channelTimeout,
		private readonly int $maxRemoteCandidates,
		private readonly \Logger $logger,
		?array $advertisedAddresses = null
	){
		if($gatheringTimeout <= 0.0 || $channelTimeout <= 0.0){
			throw new \InvalidArgumentException("Timeouts must be positive");
		}
		if($maxRemoteCandidates < 1){
			throw new \InvalidArgumentException("Maximum remote candidates must be positive, got $maxRemoteCandidates");
		}

		$this->answerBuilder = new AnswerBuilder($identityProvider, $advertisedAddresses);
	}

	public function beginNegotiation(string $offerSdp, string $networkId, CandidateMode $candidateMode = CandidateMode::BUNDLED, ?string $peerAddress = null) : Negotiation{
		if($this->shutDown){
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
			$transport = $this->transportFactory->create($this->budget);
		}catch(TransportException $e){
			throw new NegotiationException("Could not create a peer connection: " . $e->getMessage(), ErrorCode::FAILED_TO_CREATE_PEER_CONNECTION, $e);
		}

		try{
			$transport->setRemoteOffer($offer->withoutIdentity()->toString());
		}catch(TransportException $e){
			$transport->close();

			throw new NegotiationException("Peer connection rejected the offer: " . $e->getMessage(), ErrorCode::FAILED_TO_SET_REMOTE_DESCRIPTION, $e);
		}

		$negotiation = new TransportNegotiation(
			$transport,
			$networkId,
			$identity,
			$candidateMode,
			$this->answerBuilder,
			$this->gatheringTimeout,
			$this->channelTimeout,
			$this->maxRemoteCandidates
		);
		$this->negotiations[$this->nextNegotiationId++] = $negotiation;

		if($peerAddress !== null){
			$this->addSignalingPeerCandidate($negotiation, $offer, $peerAddress);
		}

		return $negotiation;
	}

	private function addSignalingPeerCandidate(TransportNegotiation $negotiation, SessionDescription $offer, string $peerAddress) : void{
		$candidate = IceCandidateFormatter::signalingPeer($offer, $peerAddress);
		if($candidate === null){
			return;
		}

		try{
			$negotiation->addRemoteCandidate($candidate);
		}catch(NegotiationException $e){
			$this->logger->debug("Signaling peer candidate for " . $negotiation->getNetworkId() . " was rejected: " . $e->getMessage());
		}
	}

	public function tick(float $now) : void{
		if($this->shutDown){
			return;
		}

		foreach($this->negotiations as $id => $negotiation){
			$negotiation->update($now);
			if(!$negotiation->isFinished()){
				continue;
			}
			unset($this->negotiations[$id]);

			$peer = $negotiation->takeEstablishedPeer();
			if($peer !== null){
				$this->established[] = $peer;
			}else{
				$this->logger->debug("Negotiation for " . $negotiation->getNetworkId() . " failed: " . ($negotiation->getFailureReason() ?? "no reason given"));
			}
		}
	}

	public function takeEstablished() : array{
		$taken = $this->established;
		$this->established = [];

		return $taken;
	}

	public function shutdown() : void{
		if($this->shutDown){
			return;
		}
		$this->shutDown = true;

		foreach($this->negotiations as $negotiation){
			$negotiation->fail("Negotiator is shutting down", ErrorCode::NO_SIGNALING_CHANNEL);
		}
		$this->negotiations = [];

		foreach($this->established as $peer){
			$peer->transport->close();
		}
		$this->established = [];
	}
}

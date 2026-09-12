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

use pocketmine\nethernet\negotiation\Negotiator;
use pocketmine\nethernet\negotiation\WebRtcNegotiator;
use pocketmine\nethernet\session\SessionManager;
use pocketmine\nethernet\signaling\SignalingException;
use pocketmine\nethernet\signaling\SignalingInterface;
use function array_values;
use function count;

final class NetherNetServer{

	/**
	 * @var SignalingInterface[]
	 * @phpstan-var list<SignalingInterface>
	 */
	private array $signaling = [];

	private bool $started = false;
	private bool $shutDown = false;

	public function __construct(
		private readonly Negotiator $negotiator,
		private readonly SessionManager $sessionManager,
		private readonly ?\Logger $logger = null
	){}

	/**
	 * Creates a server instance using the provided configuration and event listener.
	 */
	public static function create(ServerConfiguration $configuration, ServerEventListener $listener) : self{
		return new self(
			new WebRtcNegotiator(
				$configuration->identityProvider,
				$configuration->identityVerifier,
				$configuration->peerConnectionFactory,
				$configuration->budget,
				$configuration->gatheringTimeout,
				$configuration->channelTimeout,
				$configuration->maxRemoteCandidates,
				$configuration->logger
			),
			new SessionManager(
				$listener,
				$configuration->budget,
				$configuration->logger
			),
			$configuration->logger
		);
	}

	/**
	 * Registers a signaling transport (such as LAN discovery or HTTP/HTTPS).
	 */
	public function addSignaling(SignalingInterface $signaling) : void{
		if($this->started){
			throw new \LogicException("Signaling transports must be added before the server is started");
		}
		$this->signaling[] = $signaling;
	}

	/**
	 * Starts all registered signaling listeners.
	 *
	 * @throws SignalingException
	 */
	public function start() : void{
		if($this->started){
			throw new \LogicException("Server is already started");
		}
		$this->started = true;

		$started = [];
		try{
			foreach($this->signaling as $signaling){
				$signaling->start();
				$started[] = $signaling;
			}
		}catch(SignalingException $e){
			foreach($started as $alreadyStarted){
				$alreadyStarted->shutdown();
			}

			throw $e;
		}

		$this->logger?->debug("NetherNet server started with " . count($this->signaling) . " signaling transport(s)");
	}

	/**
	 * Processes one update tick for signaling, connection setup, and active sessions.
	 */
	public function tick() : void{
		if(!$this->started || $this->shutDown){
			return;
		}

		foreach($this->signaling as $signaling){
			$signaling->tick();
		}

		$this->negotiator->tick();

		foreach($this->negotiator->takeEstablished() as $peer){
			$this->sessionManager->open($peer->peerConnection, $peer->channels, $peer->networkId, $peer->identity);
		}

		$this->sessionManager->tick();
	}

	/**
	 * Shuts down all signaling listeners, ongoing handshakes, and active client sessions.
	 */
	public function shutdown() : void{
		if($this->shutDown){
			return;
		}
		$this->shutDown = true;

		foreach($this->signaling as $signaling){
			$signaling->shutdown();
		}
		$this->negotiator->shutdown();
		$this->sessionManager->shutdown();

		$this->logger?->debug("NetherNet server shut down");
	}

	public function isRunning() : bool{
		return $this->started && !$this->shutDown;
	}

	public function getSessionManager() : SessionManager{ return $this->sessionManager; }

	public function getNegotiator() : Negotiator{ return $this->negotiator; }

	/**
	 * @return SignalingInterface[]
	 * @phpstan-return list<SignalingInterface>
	 */
	public function getSignaling() : array{ return array_values($this->signaling); }
}

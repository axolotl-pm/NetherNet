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

use pmmp\webrtc\WebRtcException;
use pocketmine\nethernet\negotiation\Negotiator;
use pocketmine\nethernet\negotiation\WebRtcNegotiator;
use pocketmine\nethernet\session\DisconnectReason;
use pocketmine\nethernet\session\SessionManager;
use pocketmine\nethernet\signaling\SignalingException;
use pocketmine\nethernet\signaling\SignalingInterface;
use function array_values;
use function count;
use function strrpos;
use function substr;

final class NetherNetServer{

	/**
	 * @var SignalingInterface[]
	 * @phpstan-var list<SignalingInterface>
	 */
	private array $signaling = [];

	private readonly AddressBlockTracker $blockTracker;

	private bool $started = false;
	private bool $shutDown = false;

	public function __construct(
		private readonly Negotiator $negotiator,
		private readonly SessionManager $sessionManager,
		private readonly ?\Logger $logger = null
	){
		$this->blockTracker = new AddressBlockTracker();
	}

	/**
	 * Creates a server instance using the provided configuration and event listener.
	 */
	public static function create(ServerConfiguration $configuration, ServerEventListener $listener) : self{
		$configuration->sctp?->apply();

		return new self(
			new WebRtcNegotiator(
				$configuration->identityProvider,
				$configuration->identityVerifier,
				$configuration->peerConnectionFactory,
				$configuration->budget,
				$configuration->gatheringTimeout,
				$configuration->channelTimeout,
				$configuration->maxRemoteCandidates,
				$configuration->logger,
				$configuration->advertisedAddresses
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
		$signaling->setAddressBlockTracker($this->blockTracker);
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

		$this->blockTracker->prune();

		foreach($this->signaling as $signaling){
			$signaling->tick();
		}

		$this->negotiator->tick();

		foreach($this->negotiator->takeEstablished() as $peer){
			$address = self::stripPort($peer->peerConnection->getRemoteAddress());
			if($address !== null && $this->blockTracker->isBlocked($address)){
				$this->logger?->debug("Closed connection from blocked address: $address");
				try{
					$peer->peerConnection->close();
				}catch(WebRtcException){
				}
				continue;
			}

			$this->sessionManager->open($peer->peerConnection, $peer->channels, $peer->networkId, $peer->identity);
		}

		$this->sessionManager->tick();
	}

	/**
	 * Blocks a connection from the given address, disconnecting any active sessions from that address.
	 *
	 * @param int $timeout Seconds until the address is unblocked, or -1 to block it until unblockAddress() is called.
	 */
	public function blockAddress(string $address, int $timeout = 300) : void{
		$this->blockTracker->block($address, $timeout);
		$this->logger?->debug("Blocked $address" . ($timeout < 0 ? "" : " for $timeout seconds"));

		foreach($this->sessionManager->getSessions() as $session){
			if(self::stripPort($session->getRemoteAddress()) === $address){
				$session->initiateDisconnect(DisconnectReason::ADDRESS_BLOCKED);
			}
		}
	}

	public function unblockAddress(string $address) : void{
		$this->blockTracker->unblock($address);
		$this->logger?->debug("Unblocked $address");
	}

	private static function stripPort(?string $remoteAddress) : ?string{
		if($remoteAddress === null){
			return null;
		}

		$separator = strrpos($remoteAddress, ":");

		return $separator === false ? $remoteAddress : substr($remoteAddress, 0, $separator);
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

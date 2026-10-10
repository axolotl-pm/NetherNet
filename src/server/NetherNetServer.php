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

namespace pocketmine\nethernet\server;

use pocketmine\nethernet\negotiation\Negotiator;
use pocketmine\nethernet\negotiation\TransportNegotiator;
use pocketmine\nethernet\protocol\DisconnectReason;
use pocketmine\nethernet\protocol\Reliability;
use pocketmine\nethernet\session\SessionException;
use pocketmine\nethernet\signaling\Signaling;
use pocketmine\nethernet\signaling\SignalingContext;
use pocketmine\nethernet\signaling\SignalingException;
use pocketmine\nethernet\utils\AddressBlockTracker;
use pocketmine\nethernet\utils\InternetAddress;
use function count;
use function microtime;
use const INF;

final class NetherNetServer implements ServerInterface{

	/**
	 * @var Signaling[]
	 * @phpstan-var list<Signaling>
	 */
	private array $signaling = [];

	private readonly AddressBlockTracker $blockTracker;

	private ServerState $state = ServerState::CREATED;

	public function __construct(
		private readonly Negotiator $negotiator,
		private readonly SessionManager $sessionManager,
		private readonly \Logger $logger
	){
		$this->blockTracker = new AddressBlockTracker();
	}

	public static function create(ServerConfiguration $configuration, ServerEventListener $listener) : self{
		return new self(
			new TransportNegotiator(
				$configuration->identityProvider,
				$configuration->identityVerifier,
				$configuration->transportFactory,
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
	 * @throws \LogicException
	 */
	public function addSignaling(Signaling $signaling) : void{
		if($this->state !== ServerState::CREATED){
			throw new \LogicException("Signaling transports must be added before the server is started");
		}
		$this->signaling[] = $signaling;
	}

	/**
	 * @throws SignalingException
	 * @throws \LogicException
	 */
	public function start() : void{
		if($this->state !== ServerState::CREATED){
			throw new \LogicException("Server is already started");
		}

		$context = new SignalingContext($this->negotiator, $this->blockTracker, $this->logger);
		try{
			foreach($this->signaling as $signaling){
				$signaling->start($context);
			}
		}catch(SignalingException $e){
			$this->shutdown();

			throw $e;
		}

		$this->state = ServerState::RUNNING;
		$this->logger->debug("NetherNet server started with " . count($this->signaling) . " signaling transport(s)");
	}

	public function tick(?float $now = null) : void{
		if($this->state !== ServerState::RUNNING && $this->state !== ServerState::STOPPING){
			return;
		}
		$now ??= microtime(true);

		$this->blockTracker->update($now);

		if($this->state === ServerState::RUNNING){
			foreach($this->signaling as $signaling){
				$signaling->tick($now);
			}

			$this->negotiator->tick($now);
			$this->openEstablishedSessions();
		}

		$this->sessionManager->tick($now);

		if($this->state === ServerState::STOPPING && $this->sessionManager->count() === 0){
			$this->shutdown();
		}
	}

	private function openEstablishedSessions() : void{
		$peers = $this->negotiator->takeEstablished();
		foreach($peers as $index => $peer){
			unset($peers[$index]);

			/* The peer may have been blocked, or may be reaching us from a blocked address, since it sent its offer. */
			if($this->blockTracker->isBlocked($peer->address->ip)){
				$this->logger->debug("Closed connection from blocked address: " . $peer->address->ip);
				$peer->transport->close();
				continue;
			}

			try{
				$this->sessionManager->open($peer);
			}catch(\Throwable $e){
				/* A listener that throws takes the tick down with it; the peers not reached yet must not be left open with no owner. */
				foreach($peers as $abandoned){
					$abandoned->transport->close();
				}

				throw $e;
			}
		}
	}

	public function sendPacket(int $sessionId, string $payload, Reliability $reliability = Reliability::RELIABLE, ?int $receiptId = null) : void{
		try{
			$this->sessionManager->getSession($sessionId)?->send($payload, $reliability, $receiptId);
		}catch(SessionException $e){
			/* The session has closed itself; the next tick reports it to the listener with the reason. */
			$this->logger->debug("Session $sessionId could not be sent to: " . $e->getMessage());
		}
	}

	public function closeSession(int $sessionId, DisconnectReason $reason = DisconnectReason::SERVER_DISCONNECT) : void{
		$this->sessionManager->getSession($sessionId)?->initiateDisconnect($reason);
	}

	public function setPacketsPerTickLimit(int $limit) : void{
		$this->sessionManager->setPacketsPerTickLimit($limit);
	}

	public function blockAddress(string $address, int $timeout = 300) : void{
		$this->blockTracker->block($address, $timeout < 0 ? INF : microtime(true) + $timeout);
		$this->logger->debug("Blocked $address" . ($timeout < 0 ? "" : " for $timeout seconds"));

		$blocked = InternetAddress::normalizeIp($address) ?? $address;
		foreach($this->sessionManager->getSessions() as $session){
			$sessionIp = $session->getRemoteAddress()->ip;
			if((InternetAddress::normalizeIp($sessionIp) ?? $sessionIp) === $blocked){
				$session->initiateDisconnect(DisconnectReason::ADDRESS_BLOCKED);
			}
		}
	}

	public function unblockAddress(string $address) : void{
		$this->blockTracker->unblock($address);
		$this->logger->debug("Unblocked $address");
	}

	/**
	 * Stops accepting connections and lets sessions flush; keep ticking until the state is STOPPED.
	 */
	public function initiateShutdown(DisconnectReason $reason = DisconnectReason::SERVER_SHUTDOWN) : void{
		if($this->state !== ServerState::RUNNING){
			$this->shutdown();

			return;
		}
		$this->state = ServerState::STOPPING;

		$this->stopAcceptingConnections();
		foreach($this->sessionManager->getSessions() as $session){
			$session->initiateDisconnect($reason);
		}
	}

	public function shutdown() : void{
		if($this->state === ServerState::STOPPED){
			return;
		}
		$wasStopping = $this->state === ServerState::STOPPING;
		$this->state = ServerState::STOPPED;

		if(!$wasStopping){
			$this->stopAcceptingConnections();
		}
		$this->sessionManager->shutdown();

		$this->logger->debug("NetherNet server shut down");
	}

	private function stopAcceptingConnections() : void{
		foreach($this->signaling as $signaling){
			$signaling->shutdown();
		}
		$this->negotiator->shutdown();
	}

	public function getState() : ServerState{ return $this->state; }

	public function isRunning() : bool{
		return $this->state === ServerState::RUNNING;
	}

	public function getSessionCount() : int{
		return $this->sessionManager->count();
	}
}

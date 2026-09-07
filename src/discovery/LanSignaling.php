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

namespace pocketmine\nethernet\discovery;

use pocketmine\nethernet\discovery\packet\MessagePacket;
use pocketmine\nethernet\discovery\packet\Packet;
use pocketmine\nethernet\discovery\packet\PacketSerializer;
use pocketmine\nethernet\discovery\packet\RequestPacket;
use pocketmine\nethernet\discovery\packet\ResponsePacket;
use pocketmine\nethernet\negotiation\CandidateMode;
use pocketmine\nethernet\negotiation\ErrorCode;
use pocketmine\nethernet\negotiation\NegotiationException;
use pocketmine\nethernet\negotiation\Negotiator;
use pocketmine\nethernet\signaling\SignalingException;
use pocketmine\nethernet\signaling\SignalingInterface;
use function count;
use function socket_bind;
use function socket_clear_error;
use function socket_close;
use function socket_create;
use function socket_last_error;
use function socket_recvfrom;
use function socket_sendto;
use function socket_set_nonblock;
use function socket_set_option;
use function socket_strerror;
use function sprintf;
use function strlen;
use const AF_INET;
use const SO_BROADCAST;
use const SO_REUSEADDR;
use const SOCK_DGRAM;
use const SOCKET_ECONNRESET;
use const SOCKET_EWOULDBLOCK;
use const SOL_SOCKET;
use const SOL_UDP;

/**
 * Handles NetherNet LAN discovery broadcasts and UDP Trickle ICE signaling for peer connections.
 */
final class LanSignaling implements SignalingInterface{

	public const DEFAULT_PORT = 7551;

	/**
	 * Maximum number of datagrams to drain from the socket per tick.
	 */
	public const MAX_DATAGRAMS_PER_TICK = 256;

	public const DEFAULT_MAX_PENDING = 64;

	private const MAX_DATAGRAM_SIZE = 65535;

	private const PING_DATA = "Ping";

	private ?\Socket $socket = null;

	/**
	 * @var PendingConnection[]
	 * @phpstan-var array<string, PendingConnection>
	 */
	private array $pending = [];

	private bool $closed = false;

	/**
	 * @param int $networkId 64-bit unsigned integer identifying this host on the network.
	 */
	public function __construct(
		private readonly Negotiator $negotiator,
		private readonly ServerDataProvider $serverDataProvider,
		private readonly int $networkId,
		private readonly string $bindAddress = "0.0.0.0",
		private readonly int $port = self::DEFAULT_PORT,
		private readonly ?\Logger $logger = null,
		private readonly int $maxPending = self::DEFAULT_MAX_PENDING
	){
		if($networkId <= 0){
			throw new \InvalidArgumentException("Network id must be positive, got $networkId");
		}
		if($maxPending < 1){
			throw new \InvalidArgumentException("Maximum pending connections must be positive, got $maxPending");
		}
	}

	public function getNetworkId() : int{ return $this->networkId; }

	public function start() : void{
		if($this->socket !== null){
			throw new SignalingException("Already listening");
		}

		$socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if($socket === false){
			throw new SignalingException("Could not create a UDP socket: " . self::lastError(null));
		}

		@socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
		@socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

		if(!@socket_bind($socket, $this->bindAddress, $this->port)){
			$error = self::lastError($socket);
			socket_close($socket);

			throw new SignalingException("Could not bind to $this->bindAddress:$this->port: $error");
		}

		socket_set_nonblock($socket);
		$this->socket = $socket;

		$this->logger?->debug("LAN discovery listening on $this->bindAddress:$this->port as network id $this->networkId");
	}

	public function tick() : void{
		$socket = $this->socket;
		if($this->closed || $socket === null){
			return;
		}

		$this->receiveAll($socket);
		$this->advancePending();
	}

	public function shutdown() : void{
		if($this->closed){
			return;
		}
		$this->closed = true;

		foreach($this->pending as $pending){
			$pending->negotiation->fail("LAN discovery is shutting down", ErrorCode::NO_SIGNALING_CHANNEL);
		}
		$this->pending = [];

		if($this->socket !== null){
			socket_close($this->socket);
			$this->socket = null;
		}
	}

	private function receiveAll(\Socket $socket) : void{
		for($i = 0; $i < self::MAX_DATAGRAMS_PER_TICK; ++$i){
			$buffer = "";
			$from = "";
			$fromPort = 0;

			if(@socket_recvfrom($socket, $buffer, self::MAX_DATAGRAM_SIZE, 0, $from, $fromPort) === false){
				$error = socket_last_error($socket);
				socket_clear_error($socket);

				if($error === SOCKET_EWOULDBLOCK || $error === 0){
					return;
				}
				if($error === SOCKET_ECONNRESET){
					continue;
				}

				$this->logger?->debug("LAN discovery read failed: " . socket_strerror($error));

				return;
			}

			try{
				$this->handleDatagram($buffer, $from, $fromPort);
			}catch(DiscoveryException $e){
				$this->logger?->debug("Ignoring a datagram from $from:$fromPort: " . $e->getMessage());
			}
		}
	}

	/**
	 * @throws DiscoveryException
	 */
	private function handleDatagram(string $frame, string $address, int $port) : void{
		[$packet, $senderId] = PacketSerializer::decode($frame);

		if($senderId === $this->networkId){
			return;
		}

		if($packet instanceof RequestPacket){
			$this->send(new ResponsePacket($this->serverDataProvider->getServerData()->write()), $address, $port);

			return;
		}
		if($packet instanceof MessagePacket){
			$this->handleMessage($packet, $senderId, $address, $port);
		}
	}

	/**
	 * @throws DiscoveryException
	 */
	private function handleMessage(MessagePacket $packet, int $senderId, string $address, int $port) : void{
		if($packet->recipientId !== $this->networkId){
			return;
		}
		if($packet->data === "" || $packet->data === self::PING_DATA){
			return;
		}

		$signal = Signal::parse($packet->data);
		$key = self::keyFor($senderId, $signal->connectionId);

		match($signal->type){
			SignalType::CONNECT_REQUEST => $this->handleOffer($signal, $senderId, $key, $address, $port),
			SignalType::CANDIDATE_ADD => $this->handleCandidate($signal, $key),
			SignalType::CONNECT_ERROR => $this->handlePeerError($signal, $key),
			SignalType::CONNECT_RESPONSE => null
		};
	}

	private function handleOffer(Signal $signal, int $senderId, string $key, string $address, int $port) : void{
		if(isset($this->pending[$key])){
			return;
		}
		if(count($this->pending) >= $this->maxPending){
			$this->logger?->debug("Refusing an offer from $senderId; $this->maxPending joins are already in flight");
			$this->sendSignal($senderId, new Signal(SignalType::CONNECT_ERROR, $signal->connectionId, (string) ErrorCode::GENERIC_FAILURE->value), $address, $port);

			return;
		}

		try{
			$negotiation = $this->negotiator->beginNegotiation(
				$signal->data,
				sprintf("%u", $senderId),
				CandidateMode::TRICKLE
			);
		}catch(NegotiationException $e){
			$this->logger?->debug("Refused an offer from $senderId: " . $e->getMessage());
			$this->sendSignal($senderId, new Signal(SignalType::CONNECT_ERROR, $signal->connectionId, (string) $e->getErrorCode()->value), $address, $port);

			return;
		}

		$this->pending[$key] = new PendingConnection($negotiation, $senderId, $address, $port, $signal->connectionId);
	}

	private function handleCandidate(Signal $signal, string $key) : void{
		$pending = $this->pending[$key] ?? null;
		if($pending === null){
			return;
		}

		try{
			$pending->negotiation->addRemoteCandidate($signal->data);
		}catch(NegotiationException $e){
			$this->logger?->debug("Peer sent a candidate we cannot use: " . $e->getMessage());
		}
	}

	private function handlePeerError(Signal $signal, string $key) : void{
		$pending = $this->pending[$key] ?? null;
		$pending?->negotiation->fail("Peer reported error code " . $signal->data, ErrorCode::GENERIC_FAILURE);
	}

	/**
	 * Sends pending SDP answers and gathered Trickle ICE candidates to peers.
	 */
	private function advancePending() : void{
		foreach($this->pending as $key => $pending){
			$negotiation = $pending->negotiation;

			if($negotiation->isFailed()){
				$this->sendSignal($pending->peerId, new Signal(
					SignalType::CONNECT_ERROR,
					$pending->connectionId,
					(string) $negotiation->getFailureCode()->value
				), $pending->address, $pending->port);
				unset($this->pending[$key]);
				continue;
			}

			$answer = $negotiation->getAnswer();
			if($answer !== null && !$pending->answerSent){
				$this->sendSignal($pending->peerId, new Signal(SignalType::CONNECT_RESPONSE, $pending->connectionId, $answer), $pending->address, $pending->port);
				$pending->answerSent = true;
			}

			if($pending->answerSent){
				foreach($negotiation->takeLocalCandidates() as $candidate){
					$this->sendSignal($pending->peerId, new Signal(SignalType::CANDIDATE_ADD, $pending->connectionId, $candidate), $pending->address, $pending->port);
				}
			}

			if($negotiation->isFinished()){
				unset($this->pending[$key]);
			}
		}
	}

	private function sendSignal(int $peerId, Signal $signal, string $address, int $port) : void{
		$this->send(new MessagePacket($peerId, $signal->toString()), $address, $port);
	}

	private function send(Packet $packet, string $address, int $port) : void{
		$socket = $this->socket;
		if($socket === null){
			return;
		}

		$frame = PacketSerializer::encode($packet, $this->networkId);
		if(@socket_sendto($socket, $frame, strlen($frame), 0, $address, $port) === false){
			$this->logger?->debug("Failed to send to $address:$port: " . socket_strerror(socket_last_error($socket)));
		}
	}

	private static function keyFor(int $senderId, string $connectionId) : string{
		return $senderId . "/" . $connectionId;
	}

	private static function lastError(?\Socket $socket) : string{
		return socket_strerror($socket === null ? socket_last_error() : socket_last_error($socket));
	}
}

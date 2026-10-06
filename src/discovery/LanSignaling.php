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

use pocketmine\nethernet\AddressBlockTracker;
use pocketmine\nethernet\discovery\packet\MessagePacket;
use pocketmine\nethernet\discovery\packet\Packet;
use pocketmine\nethernet\discovery\packet\PacketSerializer;
use pocketmine\nethernet\discovery\packet\RequestPacket;
use pocketmine\nethernet\discovery\packet\ResponsePacket;
use pocketmine\nethernet\InternetAddress;
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
use const AF_INET6;
use const IPPROTO_IPV6;
use const IPV6_V6ONLY;
use const SO_BROADCAST;
use const SO_REUSEADDR;
use const SOCK_DGRAM;
use const SOCKET_ECONNRESET;
use const SOCKET_EWOULDBLOCK;
use const SOL_SOCKET;
use const SOL_UDP;

final class LanSignaling implements SignalingInterface{

	public const DEFAULT_PORT = 7551;

	/**
	 * Maximum number of datagrams to drain from the socket per tick.
	 */
	public const MAX_DATAGRAMS_PER_TICK = 256;

	public const DEFAULT_MAX_CONNECTIONS = 64;

	private const MAX_DATAGRAM_SIZE = 65535;

	private const PING_DATA = "Ping";

	private ?\Socket $socket = null;

	/**
	 * @var PendingConnection[]
	 * @phpstan-var array<string, PendingConnection>
	 */
	private array $connections = [];

	private AddressBlockTracker $blockTracker;

	private bool $shutDown = false;

	/**
	 * @param int $networkId 64-bit unsigned integer identifying this host on the network.
	 */
	public function __construct(
		private readonly Negotiator $negotiator,
		private readonly ServerDataProvider $serverDataProvider,
		private readonly int $networkId,
		private readonly InternetAddress $bindAddress = new InternetAddress("0.0.0.0", self::DEFAULT_PORT, 4),
		private readonly ?\Logger $logger = null,
		private readonly int $maxConnections = self::DEFAULT_MAX_CONNECTIONS
	){
		if($networkId <= 0){
			throw new \InvalidArgumentException("Network id must be positive, got $networkId");
		}
		if($maxConnections < 1){
			throw new \InvalidArgumentException("Maximum connections must be positive, got $maxConnections");
		}

		$this->blockTracker = new AddressBlockTracker();
	}

	public function getNetworkId() : int{ return $this->networkId; }

	public function setAddressBlockTracker(AddressBlockTracker $blockTracker) : void{
		$this->blockTracker = $blockTracker;
	}

	public function start() : void{
		if($this->socket !== null){
			throw new SignalingException("Already listening");
		}

		$ipv6 = $this->bindAddress->version === 6;
		$socket = @socket_create($ipv6 ? AF_INET6 : AF_INET, SOCK_DGRAM, SOL_UDP);
		if($socket === false){
			throw new SignalingException("Could not create a UDP socket: " . self::lastError(null));
		}

		@socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
		@socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
		if($ipv6){
			@socket_set_option($socket, IPPROTO_IPV6, IPV6_V6ONLY, 1);
		}

		if(!@socket_bind($socket, $this->bindAddress->ip, $this->bindAddress->port)){
			$error = self::lastError($socket);
			socket_close($socket);

			throw new SignalingException("Could not bind to $this->bindAddress: $error");
		}

		socket_set_nonblock($socket);
		$this->socket = $socket;

		$this->logger?->debug("LAN discovery listening on $this->bindAddress as network id $this->networkId");
	}

	public function tick() : void{
		$socket = $this->socket;
		if($this->shutDown || $socket === null){
			return;
		}

		$this->receiveAll($socket);
		$this->processConnections();
	}

	public function shutdown() : void{
		if($this->shutDown){
			return;
		}
		$this->shutDown = true;

		foreach($this->connections as $connection){
			$connection->negotiation->fail("LAN discovery is shutting down", ErrorCode::NO_SIGNALING_CHANNEL);
		}
		$this->connections = [];

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

			if($this->blockTracker->isBlocked($from)){
				continue;
			}

			$address = new InternetAddress($from, $fromPort, $this->bindAddress->version);
			try{
				$this->handleDatagram($buffer, $address);
			}catch(DiscoveryException $e){
				$this->logger?->debug("Ignoring a datagram from $address: " . $e->getMessage());
			}
		}
	}

	/**
	 * @throws DiscoveryException
	 */
	private function handleDatagram(string $frame, InternetAddress $address) : void{
		[$packet, $senderId] = PacketSerializer::decode($frame);

		if($senderId === $this->networkId){
			return;
		}

		if($packet instanceof RequestPacket){
			$this->send(new ResponsePacket($this->serverDataProvider->getServerData()->write()), $address);

			return;
		}
		if($packet instanceof MessagePacket){
			$this->handleMessage($packet, $senderId, $address);
		}
	}

	/**
	 * @throws DiscoveryException
	 */
	private function handleMessage(MessagePacket $packet, int $senderId, InternetAddress $address) : void{
		if($packet->recipientId !== $this->networkId){
			return;
		}
		if($packet->data === "" || $packet->data === self::PING_DATA){
			return;
		}

		$signal = Signal::parse($packet->data);
		$key = self::keyFor($senderId, $signal->connectionId);

		match($signal->type){
			SignalType::CONNECT_REQUEST => $this->handleOffer($signal, $senderId, $key, $address),
			SignalType::CANDIDATE_ADD => $this->handleCandidate($signal, $key),
			SignalType::CONNECT_ERROR => $this->handlePeerError($signal, $key),
			SignalType::CONNECT_RESPONSE => null
		};
	}

	private function handleOffer(Signal $signal, int $senderId, string $key, InternetAddress $address) : void{
		if(isset($this->connections[$key])){
			return;
		}
		if(count($this->connections) >= $this->maxConnections){
			$this->logger?->debug("Refused offer from $senderId: connection limit of $this->maxConnections reached");
			$this->sendSignal($senderId, new Signal(SignalType::CONNECT_ERROR, $signal->connectionId, (string) ErrorCode::GENERIC_FAILURE->value), $address);

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
			$this->sendSignal($senderId, new Signal(SignalType::CONNECT_ERROR, $signal->connectionId, (string) $e->getErrorCode()->value), $address);

			return;
		}

		$this->connections[$key] = new PendingConnection($negotiation, $senderId, $address, $signal->connectionId);
	}

	private function handleCandidate(Signal $signal, string $key) : void{
		$connection = $this->connections[$key] ?? null;
		if($connection === null){
			return;
		}

		try{
			$connection->negotiation->addRemoteCandidate($signal->data);
		}catch(NegotiationException $e){
			$this->logger?->debug("Peer sent an unusable ICE candidate: " . $e->getMessage());
		}
	}

	private function handlePeerError(Signal $signal, string $key) : void{
		$connection = $this->connections[$key] ?? null;
		$connection?->negotiation->fail("Peer reported error code " . $signal->data, ErrorCode::GENERIC_FAILURE);
	}

	private function processConnections() : void{
		foreach($this->connections as $key => $connection){
			$negotiation = $connection->negotiation;

			if($negotiation->isFailed()){
				$this->sendSignal($connection->peerId, new Signal(
					SignalType::CONNECT_ERROR,
					$connection->connectionId,
					(string) $negotiation->getFailureCode()->value
				), $connection->address);
				unset($this->connections[$key]);
				continue;
			}

			$answer = $negotiation->getAnswer();
			if($answer !== null && !$connection->answerSent){
				$this->sendSignal($connection->peerId, new Signal(SignalType::CONNECT_RESPONSE, $connection->connectionId, $answer), $connection->address);
				$connection->answerSent = true;
			}

			if($connection->answerSent){
				foreach($negotiation->takeLocalCandidates() as $candidate){
					$this->sendSignal($connection->peerId, new Signal(SignalType::CANDIDATE_ADD, $connection->connectionId, $candidate), $connection->address);
				}
			}

			if($negotiation->isFinished()){
				unset($this->connections[$key]);
			}
		}
	}

	private function sendSignal(int $peerId, Signal $signal, InternetAddress $address) : void{
		$this->send(new MessagePacket($peerId, $signal->toString()), $address);
	}

	private function send(Packet $packet, InternetAddress $address) : void{
		$socket = $this->socket;
		if($socket === null){
			return;
		}

		$frame = PacketSerializer::encode($packet, $this->networkId);
		if(@socket_sendto($socket, $frame, strlen($frame), 0, $address->ip, $address->port) === false){
			$this->logger?->debug("Failed to send to $address: " . socket_strerror(socket_last_error($socket)));
		}
	}

	private static function keyFor(int $senderId, string $connectionId) : string{
		return $senderId . "/" . $connectionId;
	}

	private static function lastError(?\Socket $socket) : string{
		return socket_strerror($socket === null ? socket_last_error() : socket_last_error($socket));
	}
}

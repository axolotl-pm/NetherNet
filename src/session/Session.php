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

namespace pocketmine\nethernet\session;

use pmmp\webrtc\ConnectionState;
use pmmp\webrtc\DataChannel;
use pmmp\webrtc\PeerConnection;
use pmmp\webrtc\WebRtcException;
use pocketmine\nethernet\identity\VerifiedIdentity;
use pocketmine\nethernet\session\framing\Assembler;
use pocketmine\nethernet\session\framing\FramingException;
use pocketmine\nethernet\session\framing\Segmenter;
use function count;

/**
 * Represents a connected client session over WebRTC data channels.
 */
final class Session{

	/**
	 * Default maximum unread incoming bytes before dropping a session.
	 */
	public const DEFAULT_MAX_RECEIVE_QUEUE_SIZE = 8388608;

	/**
	 * Default maximum unread incoming messages before dropping a session.
	 */
	public const DEFAULT_MAX_RECEIVE_QUEUE_MESSAGES = 4096;

	/**
	 * Default maximum queued outgoing bytes before dropping a session.
	 */
	public const DEFAULT_MAX_SEND_QUEUE_SIZE = 8388608;

	/**
	 * @var DataChannel[]
	 * @phpstan-var array<string, DataChannel>
	 */
	private readonly array $channels;

	/**
	 * @var Assembler[]
	 * @phpstan-var array<string, Assembler>
	 */
	private readonly array $assemblers;

	private bool $closed = false;
	private ?DisconnectReason $disconnectReason = null;

	/**
	 * @param DataChannel[] $channels Keyed by {@link Reliability} case name.
	 * @phpstan-param array<string, DataChannel> $channels
	 *
	 * @internal Created by {@link SessionManager::open()}.
	 */
	public function __construct(
		private readonly int $id,
		private readonly PeerConnection $peerConnection,
		array $channels,
		private readonly string $networkId,
		private readonly ?VerifiedIdentity $identity,
		private readonly Segmenter $segmenter = new Segmenter(),
		int $maxPayloadSize = Segmenter::MAX_PAYLOAD_SIZE,
		private readonly int $maxReceiveQueueSize = self::DEFAULT_MAX_RECEIVE_QUEUE_SIZE,
		private readonly int $maxReceiveQueueMessages = self::DEFAULT_MAX_RECEIVE_QUEUE_MESSAGES,
		private readonly int $maxSendQueueSize = self::DEFAULT_MAX_SEND_QUEUE_SIZE
	){
		$assemblers = [];
		foreach(Reliability::cases() as $reliability){
			if(!isset($channels[$reliability->name])){
				throw new \InvalidArgumentException("Missing the " . $reliability->getChannelLabel() . " channel");
			}
			$assemblers[$reliability->name] = new Assembler($reliability->isFragmentationSupported(), $maxPayloadSize);
		}

		$this->channels = $channels;
		$this->assemblers = $assemblers;
	}

	public function getId() : int{ return $this->id; }

	public function getNetworkId() : string{ return $this->networkId; }

	public function getIdentity() : ?VerifiedIdentity{ return $this->identity; }

	public function getRemoteAddress() : ?string{
		return $this->closed ? null : $this->peerConnection->getRemoteAddress();
	}

	public function getState() : ConnectionState{
		return $this->peerConnection->getState();
	}

	public function isClosed() : bool{ return $this->closed; }

	public function getDisconnectReason() : ?DisconnectReason{ return $this->disconnectReason; }

	/**
	 * Sends a message payload to the client, splitting large messages if needed on reliable channels.
	 *
	 * @throws SessionException
	 * @throws FramingException
	 */
	public function send(string $payload, Reliability $reliability = Reliability::RELIABLE) : void{
		$this->requireOpen();

		$segments = $this->segmenter->segment($payload);
		if(count($segments) === 0){
			return;
		}
		if(count($segments) > 1 && !$reliability->isFragmentationSupported()){
			throw new SessionException("Payload needs " . count($segments) . " segments, but the " . $reliability->getChannelLabel() . " cannot fragment");
		}

		try{
			$channel = $this->channels[$reliability->name];
			foreach($segments as $segment){
				$channel->send($segment);
			}
		}catch(WebRtcException $e){
			$this->close(DisconnectReason::SEND_FAILED);

			throw new SessionException("Failed to send on the " . $reliability->getChannelLabel() . ": " . $e->getMessage(), 0, $e);
		}
	}

	public function getBufferedAmount(Reliability $reliability = Reliability::RELIABLE) : int{
		return $this->closed ? 0 : $this->channels[$reliability->name]->getBufferedAmount();
	}

	/**
	 * Reads one complete incoming message from the client.
	 *
	 * @throws SessionException
	 */
	public function receive() : ?ReceivedMessage{
		$this->requireOpen();

		foreach(Reliability::cases() as $reliability){
			$channel = $this->channels[$reliability->name];
			$assembler = $this->assemblers[$reliability->name];

			try{
				while(($segment = $channel->receive()) !== null){
					$payload = $assembler->accept($segment);
					if($payload !== null){
						return new ReceivedMessage($reliability, $payload);
					}
				}
			}catch(FramingException|WebRtcException $e){
				$this->close(DisconnectReason::BAD_DATA);

				throw new SessionException("Bad data on the " . $reliability->getChannelLabel() . ": " . $e->getMessage(), 0, $e);
			}
		}

		return null;
	}

	/**
	 * Checks connection health and enforces queue size limits.
	 */
	public function checkLiveness() : bool{
		if($this->closed){
			return false;
		}

		$state = $this->peerConnection->getState();
		if($state === ConnectionState::FAILED){
			$this->close(DisconnectReason::CONNECTION_FAILED);

			return false;
		}

		/* Check queue limits before channel closures so flooding is reported accurately. */
		$queuedBytes = 0;
		$queuedMessages = 0;
		$buffered = 0;
		foreach($this->channels as $channel){
			$queuedBytes += $channel->getAvailableAmount();
			$queuedMessages += $channel->getQueuedMessageCount();
			$buffered += $channel->getBufferedAmount();
		}
		if($queuedBytes > $this->maxReceiveQueueSize){
			$this->close(DisconnectReason::RECEIVE_QUEUE_TOO_MANY_BYTES);

			return false;
		}
		if($queuedMessages > $this->maxReceiveQueueMessages){
			$this->close(DisconnectReason::RECEIVE_QUEUE_TOO_MANY_MESSAGES);

			return false;
		}
		if($buffered > $this->maxSendQueueSize){
			$this->close(DisconnectReason::SEND_QUEUE_TOO_MANY_BYTES);

			return false;
		}

		if($state === ConnectionState::CLOSED){
			$this->close(DisconnectReason::PEER_DISCONNECT);

			return false;
		}
		foreach(Reliability::cases() as $reliability){
			if($this->channels[$reliability->name]->isClosed()){
				$this->close(DisconnectReason::PEER_DISCONNECT);

				return false;
			}
		}

		return true;
	}

	public function close(DisconnectReason $reason = DisconnectReason::SERVER_DISCONNECT) : void{
		if($this->closed){
			return;
		}
		$this->closed = true;
		$this->disconnectReason = $reason;

		foreach($this->channels as $channel){
			try{
				$channel->close();
			}catch(WebRtcException){
			}
		}
		try{
			$this->peerConnection->close();
		}catch(WebRtcException){
		}
	}

	/**
	 * @throws SessionException
	 */
	private function requireOpen() : void{
		if($this->closed){
			throw new SessionException("Session is closed" . ($this->disconnectReason !== null ? ": " . $this->disconnectReason->getMessage() : ""));
		}
	}
}

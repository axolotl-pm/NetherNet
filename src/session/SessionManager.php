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

use pmmp\webrtc\DataChannel;
use pmmp\webrtc\PeerConnection;
use pocketmine\nethernet\ConnectionBudgetConfiguration;
use pocketmine\nethernet\identity\PeerIdentity;
use pocketmine\nethernet\ServerEventListener;
use pocketmine\nethernet\session\framing\Segmenter;
use function array_values;
use function count;

final class SessionManager{

	/**
	 * Maximum number of incoming messages to process per session per tick.
	 */
	public const MAX_MESSAGES_PER_TICK = 64;

	/**
	 * @var Session[]
	 * @phpstan-var array<int, Session>
	 */
	private array $sessions = [];

	private int $nextSessionId = 0;

	private readonly Segmenter $segmenter;

	public function __construct(
		private readonly ServerEventListener $listener,
		private readonly ConnectionBudgetConfiguration $budget,
		private readonly ?\Logger $logger = null
	){
		$this->segmenter = $budget->createSegmenter();
	}

	/**
	 * @phpstan-param array<string, DataChannel> $channels
	 */
	public function open(PeerConnection $peerConnection, array $channels, string $networkId, ?PeerIdentity $identity) : Session{
		$session = new Session(
			$this->nextSessionId++,
			$peerConnection,
			$channels,
			$networkId,
			$identity,
			self::segmenterFor($channels, $this->segmenter),
			$this->budget
		);
		$this->sessions[$session->getId()] = $session;

		$this->logger?->debug("Session " . $session->getId() . " opened for network id $networkId");

		try{
			$this->listener->onSessionOpen($session);
		}catch(\Throwable $e){
			$session->close(DisconnectReason::REJECTED_BY_HOST);
			$this->forget($session);

			throw $e;
		}

		return $session;
	}

	/**
	 * @phpstan-param array<string, DataChannel> $channels
	 */
	private static function segmenterFor(array $channels, Segmenter $configured) : Segmenter{
		$negotiated = null;
		foreach($channels as $channel){
			$size = $channel->getMaxMessageSize();
			if($negotiated === null || $size < $negotiated){
				$negotiated = $size;
			}
		}

		$payload = $negotiated === null ? 0 : $negotiated - 1;

		return $payload >= 1 && $payload < $configured->getMaxSegmentPayloadSize() ? new Segmenter($payload) : $configured;
	}

	public function tick() : void{
		foreach($this->sessions as $session){
			if(!$session->checkLiveness()){
				$this->forget($session);
				continue;
			}

			$this->drain($session);
		}
	}

	private function drain(Session $session) : void{
		for($i = 0; $i < self::MAX_MESSAGES_PER_TICK; ++$i){
			if(!$this->listener->canAcceptPackets()){
				return;
			}

			try{
				$message = $session->receive();
			}catch(SessionException $e){
				$this->logger?->debug("Session " . $session->getId() . " failed while reading: " . $e->getMessage());
				$this->forget($session);

				return;
			}

			if($message === null){
				return;
			}

			$this->listener->onPacketReceive($session, $message->payload, $message->reliability);

			if($session->isClosed()){
				$this->forget($session);

				return;
			}
		}
	}

	/**
	 * Initiates a graceful disconnect.
	 */
	public function close(Session $session, DisconnectReason $reason = DisconnectReason::SERVER_DISCONNECT) : void{
		$session->initiateDisconnect($reason);
	}

	private function forget(Session $session) : void{
		if(!isset($this->sessions[$session->getId()])){
			return;
		}
		unset($this->sessions[$session->getId()]);

		/* Preserves the original disconnect reason if the session was already closed. */
		$session->close();
		$reason = $session->getDisconnectReason() ?? DisconnectReason::SERVER_DISCONNECT;

		$this->logger?->debug("Session " . $session->getId() . " closed: " . $reason->getMessage());
		$this->listener->onSessionClose($session, $reason);
	}

	public function getSession(int $id) : ?Session{
		return $this->sessions[$id] ?? null;
	}

	/**
	 * @return Session[]
	 * @phpstan-return list<Session>
	 */
	public function getSessions() : array{
		return array_values($this->sessions);
	}

	public function count() : int{
		return count($this->sessions);
	}

	public function shutdown(DisconnectReason $reason = DisconnectReason::SERVER_SHUTDOWN) : void{
		foreach($this->sessions as $session){
			$session->close($reason);
			$this->forget($session);
		}
	}
}

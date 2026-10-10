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

use pocketmine\nethernet\identity\PeerIdentity;
use pocketmine\nethernet\protocol\DisconnectReason;
use pocketmine\nethernet\protocol\Reliability;
use pocketmine\nethernet\server\ServerEventListener;
use pocketmine\nethernet\utils\InternetAddress;

/**
 * Keeps every event in the order it arrived, and lets a test play the part of a listener that pushes back.
 */
final class RecordingEventListener implements ServerEventListener{

	/** @phpstan-var list<array{int, string, InternetAddress, ?PeerIdentity}> */
	public array $opened = [];
	/** @phpstan-var list<array{int, string, Reliability}> */
	public array $packets = [];
	/** @phpstan-var list<array{int, DisconnectReason}> */
	public array $closed = [];

	/** @phpstan-var list<array{int, int}> */
	public array $acks = [];
	/** @phpstan-var list<array{int, int}> */
	public array $bandwidth = [];
	/** @phpstan-var list<array{int, int}> */
	public array $pings = [];

	public bool $accepting = true;
	public ?\Throwable $failOpenWith = null;

	/** @phpstan-var (\Closure(int, string) : void)|null */
	public ?\Closure $onPacket = null;

	public function onSessionOpen(int $sessionId, string $networkId, InternetAddress $address, ?PeerIdentity $identity) : void{
		$this->opened[] = [$sessionId, $networkId, $address, $identity];
		if($this->failOpenWith !== null){
			throw $this->failOpenWith;
		}
	}

	public function canAcceptPackets() : bool{
		return $this->accepting;
	}

	public function onPacketReceive(int $sessionId, string $payload, Reliability $reliability) : void{
		$this->packets[] = [$sessionId, $payload, $reliability];
		if($this->onPacket !== null){
			($this->onPacket)($sessionId, $payload);
		}
	}

	public function onSessionClose(int $sessionId, DisconnectReason $reason) : void{
		$this->closed[] = [$sessionId, $reason];
	}

	public function onPacketAck(int $sessionId, int $receiptId) : void{
		$this->acks[] = [$sessionId, $receiptId];
	}

	public function onBandwidthStatsUpdate(int $bytesSentDiff, int $bytesReceivedDiff) : void{
		$this->bandwidth[] = [$bytesSentDiff, $bytesReceivedDiff];
	}

	public function onPingMeasure(int $sessionId, int $pingMs) : void{
		$this->pings[] = [$sessionId, $pingMs];
	}
}

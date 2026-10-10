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

use pocketmine\nethernet\identity\PeerIdentity;
use pocketmine\nethernet\protocol\DisconnectReason;
use pocketmine\nethernet\protocol\Reliability;
use pocketmine\nethernet\utils\InternetAddress;

interface ServerEventListener{

	/**
	 * If this throws, the session is closed without onSessionClose() being called.
	 */
	public function onSessionOpen(int $sessionId, string $networkId, InternetAddress $address, ?PeerIdentity $identity) : void;

	public function onSessionClose(int $sessionId, DisconnectReason $reason) : void;

	/**
	 * Asked before every packet is read. Returning false leaves the packet in the client's receive queue.
	 */
	public function canAcceptPackets() : bool;

	public function onPacketReceive(int $sessionId, string $payload, Reliability $reliability) : void;

	/**
	 * Called when a packet sent with a receipt ID has left the send queue.
	 */
	public function onPacketAck(int $sessionId, int $receiptId) : void;

	public function onBandwidthStatsUpdate(int $bytesSentDiff, int $bytesReceivedDiff) : void;

	public function onPingMeasure(int $sessionId, int $pingMs) : void;
}

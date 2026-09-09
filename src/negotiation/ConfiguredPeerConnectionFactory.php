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

use pmmp\webrtc\IceServer;
use pmmp\webrtc\PeerConnection;
use pmmp\webrtc\PeerConnectionOptions;
use pocketmine\nethernet\ConnectionBudgetConfiguration;
use function count;

/**
 * Creates WebRTC PeerConnection instances configured with preset options and ICE servers.
 */
final class ConfiguredPeerConnectionFactory implements PeerConnectionFactory{

	/**
	 * @param IceServer[] $iceServers
	 * @phpstan-param list<IceServer> $iceServers
	 *
	 * @param string|null $bindAddress    Local address to bind ICE sockets, or null for all interfaces.
	 * @param int|null    $portRangeBegin Start of UDP port range.
	 * @param int|null    $portRangeEnd   End of UDP port range.
	 */
	public function __construct(
		private readonly array $iceServers = [],
		private readonly ?string $bindAddress = null,
		private readonly ?int $portRangeBegin = null,
		private readonly ?int $portRangeEnd = null
	){
		if(($portRangeBegin === null) !== ($portRangeEnd === null)){
			throw new \InvalidArgumentException("Port range needs both a start and an end, or neither");
		}
		if($portRangeBegin !== null && $portRangeEnd !== null && $portRangeBegin > $portRangeEnd){
			throw new \InvalidArgumentException("Port range start $portRangeBegin is above its end $portRangeEnd");
		}
	}

	public function create(ConnectionBudgetConfiguration $budget) : PeerConnection{
		// Options are created per connection because PeerConnectionOptions setters mutate the instance in place.
		$options = PeerConnectionOptions::create()
			->setMaxMessageSize($budget->maxMessageSize)
			->setMaxReceiveQueueSize($budget->getNativeReceiveQueueSize())
			->setMaxReceiveQueueMessages($budget->getNativeReceiveQueueMessages())
			->setMaxSendQueueSize($budget->getNativeSendQueueSize())
			->setMaxPendingDataChannels($budget->maxPendingDataChannels)
			->setIceTcpEnabled(false);

		if($this->bindAddress !== null){
			$options->setBindAddress($this->bindAddress);
		}
		if($this->portRangeBegin !== null && $this->portRangeEnd !== null){
			$options->setPortRange($this->portRangeBegin, $this->portRangeEnd);
		}
		if(count($this->iceServers) > 0){
			$options->setIceServers(...$this->iceServers);
		}

		return new PeerConnection($options);
	}
}

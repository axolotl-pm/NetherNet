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
use pocketmine\nethernet\session\framing\Segmenter;
use pocketmine\nethernet\session\Session;
use function count;

/**
 * Creates WebRTC PeerConnection instances configured with preset options and ICE servers.
 */
final class ConfiguredPeerConnectionFactory implements PeerConnectionFactory{

	public const DEFAULT_MAX_MESSAGE_SIZE = Segmenter::MAX_SEGMENT_PAYLOAD_SIZE + 1;

	private readonly PeerConnectionOptions $options;

	/**
	 * @param IceServer[] $iceServers
	 * @phpstan-param list<IceServer> $iceServers
	 *
	 * @param string|null $bindAddress             Local address to bind ICE sockets, or null for all interfaces.
	 * @param int|null    $portRangeBegin          Start of UDP port range.
	 * @param int|null    $portRangeEnd            End of UDP port range.
	 * @param int         $maxMessageSize          Maximum advertised SCTP message size.
	 * @param int         $maxReceiveQueueSize     Maximum receive queue size in bytes.
	 * @param int         $maxReceiveQueueMessages Maximum receive queue message count.
	 * @param int         $maxSendQueueSize        Maximum send queue size in bytes.
	 */
	public function __construct(
		array $iceServers = [],
		?string $bindAddress = null,
		?int $portRangeBegin = null,
		?int $portRangeEnd = null,
		int $maxMessageSize = self::DEFAULT_MAX_MESSAGE_SIZE,
		int $maxReceiveQueueSize = Session::DEFAULT_MAX_RECEIVE_QUEUE_SIZE * 2,
		int $maxReceiveQueueMessages = Session::DEFAULT_MAX_RECEIVE_QUEUE_MESSAGES * 2,
		int $maxSendQueueSize = Session::DEFAULT_MAX_SEND_QUEUE_SIZE * 2
	){
		if(($portRangeBegin === null) !== ($portRangeEnd === null)){
			throw new \InvalidArgumentException("Port range needs both a start and an end, or neither");
		}
		if($portRangeBegin !== null && $portRangeEnd !== null && $portRangeBegin > $portRangeEnd){
			throw new \InvalidArgumentException("Port range start $portRangeBegin is above its end $portRangeEnd");
		}
		if($maxMessageSize < 2 || $maxMessageSize > self::DEFAULT_MAX_MESSAGE_SIZE){
			throw new \InvalidArgumentException("Maximum message size must be between 2 and " . self::DEFAULT_MAX_MESSAGE_SIZE . ", got $maxMessageSize");
		}

		/*
		 * Higher than per-session limits so sessions close with a reason
		 * before the native extension rejects them.
		 */
		$options = PeerConnectionOptions::create()
			->setMaxMessageSize($maxMessageSize)
			->setMaxReceiveQueueSize($maxReceiveQueueSize)
			->setMaxReceiveQueueMessages($maxReceiveQueueMessages)
			->setMaxSendQueueSize($maxSendQueueSize)
			->setIceTcpEnabled(false);

		if($bindAddress !== null){
			$options = $options->setBindAddress($bindAddress);
		}
		if($portRangeBegin !== null && $portRangeEnd !== null){
			$options = $options->setPortRange($portRangeBegin, $portRangeEnd);
		}
		if(count($iceServers) > 0){
			$options = $options->setIceServers(...$iceServers);
		}

		$this->options = $options;
	}

	public function create() : PeerConnection{
		return new PeerConnection($this->options);
	}
}

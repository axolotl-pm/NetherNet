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

namespace pocketmine\nethernet\signaling\lan\packet;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use function strlen;

final class PacketSerializer{

	private const HEADER_SIZE = 18;

	public const RELIABLE_VERSION = 1;

	private function __construct(){}

	public static function encode(Packet $packet) : string{
		return DiscoveryCipher::seal(self::encodePlaintext($packet, $packet->getType()->isReliable() ? self::RELIABLE_VERSION : 0));
	}

	public static function encodePlaintext(Packet $packet, int $version) : string{
		$body = new ByteBufferWriter();
		LE::writeUnsignedShort($body, $packet->getType()->value);
		LE::writeUnsignedLong($body, $packet->senderId);
		LE::writeUnsignedLong($body, $version);
		$packet->encodeBody($body);

		$payload = new ByteBufferWriter();
		LE::writeUnsignedShort($payload, strlen($body->getData()) + 2);
		$payload->writeByteArray($body->getData());

		return $payload->getData();
	}

	/**
	 * @throws DiscoveryException
	 */
	public static function decode(string $frame) : Packet{
		$in = new ByteBufferReader(DiscoveryCipher::open($frame));

		try{
			[$type, $senderId, $version] = self::readHeader($in);
			if($type->isReliable() && $version !== self::RELIABLE_VERSION){
				throw new DiscoveryException("Reliable packet has protocol version $version");
			}

			$packet = match($type){
				PacketType::REQUEST => new RequestPacket($senderId),
				PacketType::RESPONSE => ResponsePacket::decodeBody($senderId, $in),
				PacketType::MESSAGE => MessagePacket::decodeBody($senderId, $in),
				PacketType::RELIABLE => ReliablePacket::decodeBody($senderId, $in),
				PacketType::ACKNOWLEDGEMENT => AcknowledgementPacket::decodeBody($senderId, $in)
			};

			if($in->getUnreadLength() !== 0){
				throw new DiscoveryException("Datagram has " . $in->getUnreadLength() . " unread bytes remaining");
			}
		}catch(DataDecodeException $e){
			throw new DiscoveryException("Unexpected end of datagram payload: " . $e->getMessage(), 0, $e);
		}

		return $packet;
	}

	/**
	 * @throws DataDecodeException
	 * @throws DiscoveryException
	 */
	public static function decodeWrappedMessage(ByteBufferReader $in) : MessagePacket{
		[$type, $senderId, $version] = self::readHeader($in);
		if($type !== PacketType::MESSAGE || $version !== self::RELIABLE_VERSION){
			throw new DiscoveryException("Invalid wrapped message of type $type->name and protocol version $version");
		}

		return MessagePacket::decodeBody($senderId, $in);
	}

	/**
	 * @return array{PacketType, int, int}
	 *
	 * @throws DataDecodeException
	 * @throws DiscoveryException
	 */
	private static function readHeader(ByteBufferReader $in) : array{
		if($in->getUnreadLength() < self::HEADER_SIZE + 2){
			throw new DiscoveryException("Datagram is too short to hold a header");
		}

		LE::readUnsignedShort($in);

		$rawType = LE::readUnsignedShort($in);
		$senderId = LE::readUnsignedLong($in);
		$version = LE::readUnsignedLong($in);

		$type = PacketType::tryFrom($rawType) ?? throw new DiscoveryException("Unknown packet type $rawType");
		if($version < 0 || $version > self::RELIABLE_VERSION){
			throw new DiscoveryException("Unsupported protocol version $version");
		}

		return [$type, $senderId, $version];
	}
}

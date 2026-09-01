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

namespace pocketmine\nethernet\discovery\packet;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pocketmine\nethernet\discovery\DiscoveryCipher;
use pocketmine\nethernet\discovery\DiscoveryException;
use function str_repeat;
use function strlen;

/**
 * Serializes and deserializes discovery packets with their headers.
 */
final class PacketSerializer{

	private const HEADER_SIZE = 18;

	private const PADDING_SIZE = 8;

	private function __construct(){}

	public static function encode(Packet $packet, int $senderId) : string{
		$body = new ByteBufferWriter();
		LE::writeUnsignedShort($body, $packet->getType()->value);
		LE::writeUnsignedLong($body, $senderId);
		$body->writeByteArray(str_repeat("\x00", self::PADDING_SIZE));
		$packet->encodeBody($body);

		$payload = new ByteBufferWriter();
		LE::writeUnsignedShort($payload, strlen($body->getData()) + 2);
		$payload->writeByteArray($body->getData());

		return DiscoveryCipher::seal($payload->getData());
	}

	/**
	 * @return array{Packet, int}
	 *
	 * @throws DiscoveryException
	 */
	public static function decode(string $frame) : array{
		$payload = DiscoveryCipher::open($frame);
		if(strlen($payload) < self::HEADER_SIZE + 2){
			throw new DiscoveryException("Datagram is too short to hold a header");
		}

		$in = new ByteBufferReader($payload);

		try{
			LE::readUnsignedShort($in);

			$rawType = LE::readUnsignedShort($in);
			$senderId = LE::readUnsignedLong($in);
			$in->readByteArray(self::PADDING_SIZE);

			$type = PacketType::tryFrom($rawType);
			if($type === null){
				throw new DiscoveryException("Unknown packet type $rawType");
			}

			$packet = match($type){
				PacketType::REQUEST => new RequestPacket(),
				PacketType::RESPONSE => new ResponsePacket(),
				PacketType::MESSAGE => new MessagePacket()
			};
			$packet->decodeBody($in);

			if($in->getUnreadLength() !== 0){
				throw new DiscoveryException("Datagram has " . $in->getUnreadLength() . " bytes left over");
			}
		}catch(DataDecodeException $e){
			throw new DiscoveryException("Datagram ended early: " . $e->getMessage(), 0, $e);
		}

		return [$packet, $senderId];
	}
}

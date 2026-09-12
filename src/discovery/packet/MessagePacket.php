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
use pmmp\encoding\LE;
use pocketmine\nethernet\discovery\DiscoveryException;
use function strlen;

final class MessagePacket extends Packet{

	private const MAX_DATA_LENGTH = 65535;

	public function __construct(
		public int $recipientId = 0,
		public string $data = ""
	){}

	public function getType() : PacketType{ return PacketType::MESSAGE; }

	public function decodeBody(ByteBufferReader $in) : void{
		$this->recipientId = LE::readUnsignedLong($in);

		$length = LE::readUnsignedInt($in);
		if($length > self::MAX_DATA_LENGTH){
			throw new DiscoveryException("Message of $length bytes is larger than a datagram can carry");
		}

		$this->data = $in->readByteArray($length);

		// Read any trailing bytes in the payload to maintain compatibility with vanilla packets
		$trailing = $in->getUnreadLength();
		if($trailing > 0){
			$this->data .= $in->readByteArray($trailing);
		}
	}

	public function encodeBody(ByteBufferWriter $out) : void{
		LE::writeUnsignedLong($out, $this->recipientId);
		LE::writeUnsignedInt($out, strlen($this->data));
		$out->writeByteArray($this->data);
	}
}

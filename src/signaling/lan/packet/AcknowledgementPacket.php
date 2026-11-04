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

final class AcknowledgementPacket extends Packet{

	public function __construct(
		int $senderId,
		public readonly int $recipientId,
		public readonly int $nonce,
		public readonly int $sequence
	){
		parent::__construct($senderId);
	}

	public function getType() : PacketType{ return PacketType::ACKNOWLEDGEMENT; }

	/**
	 * @throws DataDecodeException
	 * @throws DiscoveryException
	 */
	public static function decodeBody(int $senderId, ByteBufferReader $in) : self{
		$recipientId = LE::readUnsignedLong($in);
		$nonce = LE::readUnsignedLong($in);
		$sequence = LE::readUnsignedLong($in);
		if($senderId === 0 || $recipientId === 0 || $nonce === 0 || $sequence === 0){
			throw new DiscoveryException("Acknowledgement has a zero identifier");
		}

		return new self($senderId, $recipientId, $nonce, $sequence);
	}

	public function encodeBody(ByteBufferWriter $out) : void{
		LE::writeUnsignedLong($out, $this->recipientId);
		LE::writeUnsignedLong($out, $this->nonce);
		LE::writeUnsignedLong($out, $this->sequence);
	}
}

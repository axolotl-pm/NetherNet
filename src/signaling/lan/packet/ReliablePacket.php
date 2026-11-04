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

/**
 * Contains a message that the recipient acknowledges with an {@link AcknowledgementPacket}.
 */
final class ReliablePacket extends Packet{

	/**
	 * @param int $nonce    nonzero value the sender picks once and keeps for every reliable packet it sends
	 * @param int $sequence nonzero, increasing per reliable packet from the same sender and nonce
	 */
	public function __construct(
		int $senderId,
		public readonly int $recipientId,
		public readonly int $nonce,
		public readonly int $sequence,
		public readonly MessagePacket $message
	){
		parent::__construct($senderId);
	}

	public function getType() : PacketType{ return PacketType::RELIABLE; }

	/**
	 * @throws DataDecodeException
	 * @throws DiscoveryException
	 */
	public static function decodeBody(int $senderId, ByteBufferReader $in) : self{
		$recipientId = LE::readUnsignedLong($in);
		$nonce = LE::readUnsignedLong($in);
		$sequence = LE::readUnsignedLong($in);
		if($senderId === 0 || $recipientId === 0 || $nonce === 0 || $sequence === 0){
			throw new DiscoveryException("Reliable packet has a zero identifier");
		}

		$message = PacketSerializer::decodeWrappedMessage($in);
		if($message->senderId !== $senderId || $message->recipientId !== $recipientId){
			throw new DiscoveryException("Wrapped message is addressed differently from the reliable packet");
		}

		return new self($senderId, $recipientId, $nonce, $sequence, $message);
	}

	public function encodeBody(ByteBufferWriter $out) : void{
		LE::writeUnsignedLong($out, $this->recipientId);
		LE::writeUnsignedLong($out, $this->nonce);
		LE::writeUnsignedLong($out, $this->sequence);
		$out->writeByteArray(PacketSerializer::encodePlaintext($this->message, PacketSerializer::RELIABLE_VERSION));
	}
}

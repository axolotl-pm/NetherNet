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
use function bin2hex;
use function hex2bin;
use function strlen;

/**
 * Server discovery response packet advertising server metadata.
 */
final class ResponsePacket extends Packet{

	private const MAX_ENCODED_LENGTH = 65535;

	public function __construct(
		public string $applicationData = ""
	){}

	public function getType() : PacketType{ return PacketType::RESPONSE; }

	public function decodeBody(ByteBufferReader $in) : void{
		$length = LE::readUnsignedInt($in);
		if($length > self::MAX_ENCODED_LENGTH){
			throw new DiscoveryException("Advertised application data of $length bytes is implausible");
		}

		$decoded = hex2bin($in->readByteArray($length));
		if($decoded === false){
			throw new DiscoveryException("Application data is not valid hex");
		}

		$this->applicationData = $decoded;
	}

	public function encodeBody(ByteBufferWriter $out) : void{
		$hex = bin2hex($this->applicationData);
		LE::writeUnsignedInt($out, strlen($hex));
		$out->writeByteArray($hex);
	}
}

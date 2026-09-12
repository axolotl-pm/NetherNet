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

abstract class Packet{

	abstract public function getType() : PacketType;

	/**
	 * @throws DataDecodeException
	 * @throws \pocketmine\nethernet\discovery\DiscoveryException
	 */
	abstract public function decodeBody(ByteBufferReader $in) : void;

	abstract public function encodeBody(ByteBufferWriter $out) : void;
}

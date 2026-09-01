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

namespace pocketmine\nethernet\discovery;

use PHPUnit\Framework\TestCase;
use function bin2hex;
use function chr;
use function hex2bin;
use function str_repeat;
use function strlen;
use function substr;

final class ServerDataTest extends TestCase{

	/**
	 * A vector taken from a working implementation.
	 *
	 * The field encodings are not uniform, and the two that look alike are not: the
	 * game mode is a zigzag varint that encodes 2 as 0x04, while the player counts
	 * beside it are fixed-width little-endian. With small numbers the difference is
	 * invisible unless it is pinned, and getting it wrong shifts every later field.
	 */
	private const REFERENCE = "06"
		. "06" . "736572766572"          //"server"
		. "05" . "776f726c64"            //"world"
		. "04"                           //zigzag varint 2, Adventure
		. "01000000"                     //int32 LE 1
		. "08000000"                     //int32 LE 8
		. "00" . "00" . "01" . "01"      //editor, hardcore, online auth, self-signed auth
		. "00"                           //nonce, length-prefixed and empty here
		. "04"                           //zigzag varint 2, NetherNet
		. "08";                          //zigzag varint 4, connection type

	private static function reference() : ServerData{
		return new ServerData(
			serverName: "server",
			levelName: "world",
			gameType: GameType::ADVENTURE,
			playerCount: 1,
			maxPlayerCount: 8,
			editorWorld: false,
			hardcore: false,
			acceptsOnlineAuth: true,
			acceptsSelfSignedAuth: true,
			transportLayer: TransportLayer::NETHERNET,
			connectionType: ServerData::CONNECTION_TYPE_NETHERNET
		);
	}

	public function testMatchesTheReferenceEncoding() : void{
		self::assertSame(self::REFERENCE, bin2hex(self::reference()->write()));
		self::assertSame(30, strlen(self::reference()->write()));
	}

	/**
	 * The nonce sits between the auth flags and the transport layer. Writing it
	 * anywhere else still produces a readable length prefix, so the mistake only
	 * shows up as the two varints after it decoding into nonsense.
	 */
	public function testNonceIsCarriedBetweenTheAuthFlagsAndTheTransport() : void{
		$data = new ServerData(
			serverName: "server",
			levelName: "world",
			gameType: GameType::ADVENTURE,
			playerCount: 1,
			maxPlayerCount: 8,
			nonce: "a1b2c3"
		);

		self::assertSame(
			"060673657276657205776f726c6404010000000800000000000101066131623263330408",
			bin2hex($data->write())
		);

		$read = ServerData::read($data->write());
		self::assertSame("a1b2c3", $read->nonce);
		self::assertSame(TransportLayer::NETHERNET, $read->transportLayer);
		self::assertSame(ServerData::CONNECTION_TYPE_NETHERNET, $read->connectionType);
	}

	public function testReferenceEncodingIsReadBack() : void{
		$bytes = hex2bin(self::REFERENCE);
		self::assertIsString($bytes);

		$data = ServerData::read($bytes);

		self::assertSame("server", $data->serverName);
		self::assertSame("world", $data->levelName);
		self::assertSame(GameType::ADVENTURE, $data->gameType);
		self::assertSame(1, $data->playerCount);
		self::assertSame(8, $data->maxPlayerCount);
		self::assertFalse($data->editorWorld);
		self::assertFalse($data->hardcore);
		self::assertTrue($data->acceptsOnlineAuth);
		self::assertTrue($data->acceptsSelfSignedAuth);
		self::assertSame(TransportLayer::NETHERNET, $data->transportLayer);
		self::assertSame(ServerData::CONNECTION_TYPE_NETHERNET, $data->connectionType);
	}

	/**
	 * The transport values are 0, 2 and 4 rather than consecutive. Advertising the
	 * wrong one points clients at a transport the host is not running.
	 */
	public function testTransportValuesAreNotConsecutive() : void{
		self::assertSame(0, TransportLayer::RAKNET->value);
		self::assertSame(2, TransportLayer::NETHERNET->value);
		self::assertSame(4, TransportLayer::DEFAULT->value);
	}

	/**
	 * String lengths count bytes, not characters. Using a character count truncates
	 * every world whose name is not plain ASCII.
	 */
	public function testStringLengthsCountBytes() : void{
		$name = "Café";
		$written = (new ServerData(serverName: $name, levelName: ""))->write();

		//version, then the varint length of the UTF-8 bytes
		self::assertSame(strlen($name), 5);
		self::assertSame(chr(5), substr($written, 1, 1));
		self::assertSame($name, ServerData::read($written)->serverName);
	}

	public function testRoundTripPreservesEveryField() : void{
		$data = new ServerData(
			serverName: "a host",
			levelName: "a world",
			gameType: GameType::CREATIVE,
			playerCount: 123,
			maxPlayerCount: 200,
			editorWorld: true,
			hardcore: true,
			acceptsOnlineAuth: false,
			acceptsSelfSignedAuth: false,
			transportLayer: TransportLayer::RAKNET,
			connectionType: 0
		);

		self::assertEquals($data, ServerData::read($data->write()));
	}

	/** The layout changes between versions, so an older one cannot be read at all. */
	public function testWrongVersionIsRejected() : void{
		$bytes = self::reference()->write();

		$this->expectException(DiscoveryException::class);
		ServerData::read(chr(4) . substr($bytes, 1));
	}

	public function testTrailingBytesAreRejected() : void{
		$this->expectException(DiscoveryException::class);
		ServerData::read(self::reference()->write() . "\x00");
	}

	public function testTruncatedAdvertIsRejected() : void{
		$bytes = self::reference()->write();

		$this->expectException(DiscoveryException::class);
		ServerData::read(substr($bytes, 0, 10));
	}

	public function testUnknownGameTypeIsRejected() : void{
		$bytes = hex2bin("05" . "00" . "00" . "10" . "00000000" . "00000000" . "00000000" . "04" . "08");
		self::assertIsString($bytes);

		$this->expectException(DiscoveryException::class);
		ServerData::read($bytes);
	}

	/** Empty names are legal, and produce a one-byte length of zero. */
	public function testEmptyNamesAreAllowed() : void{
		$data = new ServerData(serverName: "", levelName: "");

		self::assertSame("", ServerData::read($data->write())->serverName);
		self::assertSame(str_repeat("00", 2), substr(bin2hex($data->write()), 2, 4));
	}
}

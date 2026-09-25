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
use function strlen;
use function substr;

final class ServerDataTest extends TestCase{

	private const REFERENCE = "07"
		. "06" . "736572766572"          //"server"
		. "8a22"                         //zigzag varint 2181, protocol
		. "07" . "312e32362e3530"        //"1.26.50"
		. "05" . "776f726c64"            //"world"
		. "02"                           //zigzag varint 1, player count
		. "10"                           //zigzag varint 8, max player count
		. "04"                           //zigzag varint 2, Adventure
		. "00" . "01" . "01" . "01"      //editor, hardcore, online auth, self-signed auth
		. "05" . "6e6f6e6365"            //"nonce"
		. "08";                          //zigzag varint 4, connection type

	private static function reference() : ServerData{
		return new ServerData(
			serverName: "server",
			protocol: 2181,
			version: "1.26.50",
			levelName: "world",
			gameType: GameType::ADVENTURE,
			playerCount: 1,
			maxPlayerCount: 8,
			editorWorld: false,
			hardcore: true,
			acceptsOnlineAuth: true,
			acceptsSelfSignedAuth: true,
			nonce: "nonce",
			connectionType: ServerData::CONNECTION_TYPE_NETHERNET
		);
	}

	public function testMatchesTheReferenceEncoding() : void{
		self::assertSame(self::REFERENCE, bin2hex(self::reference()->write()));
		self::assertSame(38, strlen(self::reference()->write()));
	}

	/**
	 * The nonce sits between the auth flags and the connection type. Writing it
	 * anywhere else still produces a readable length prefix, so the mistake only
	 * shows up as the varint after it decoding into nonsense.
	 */
	public function testNonceIsCarriedBetweenTheAuthFlagsAndTheConnectionType() : void{
		$data = new ServerData(
			serverName: "server",
			protocol: 2181,
			version: "1.26.50",
			levelName: "world",
			gameType: GameType::ADVENTURE,
			playerCount: 1,
			maxPlayerCount: 8,
			nonce: "a1b2c3"
		);

		self::assertSame(
			"07067365727665728a2207312e32362e353005776f726c64021004000001010661316232633308",
			bin2hex($data->write())
		);

		$read = ServerData::read($data->write());
		self::assertSame("a1b2c3", $read->nonce);
		self::assertSame(ServerData::CONNECTION_TYPE_NETHERNET, $read->connectionType);
	}

	public function testReferenceEncodingIsReadBack() : void{
		$bytes = hex2bin(self::REFERENCE);
		self::assertIsString($bytes);

		$data = ServerData::read($bytes);

		self::assertSame("server", $data->serverName);
		self::assertSame(2181, $data->protocol);
		self::assertSame("1.26.50", $data->version);
		self::assertSame("world", $data->levelName);
		self::assertSame(GameType::ADVENTURE, $data->gameType);
		self::assertSame(1, $data->playerCount);
		self::assertSame(8, $data->maxPlayerCount);
		self::assertFalse($data->editorWorld);
		self::assertTrue($data->hardcore);
		self::assertTrue($data->acceptsOnlineAuth);
		self::assertTrue($data->acceptsSelfSignedAuth);
		self::assertSame("nonce", $data->nonce);
		self::assertSame(ServerData::CONNECTION_TYPE_NETHERNET, $data->connectionType);
	}

	/**
	 * The protocol and game version come from the third and fourth pong fields.
	 * Dropping them would advertise protocol 0 and an empty version for every host
	 * configured from a pong.
	 */
	public function testPongDataCarriesTheProtocolAndVersion() : void{
		$data = ServerData::fromPongData("MCPE;Dedicated Server;800;1.21.100;3;20;0;World;Creative;1;19132;19133;");
		self::assertNotNull($data);

		self::assertSame(800, $data->protocol);
		self::assertSame("1.21.100", $data->version);
	}

	/**
	 * String lengths count bytes, not characters. Using a character count truncates
	 * every world whose name is not plain ASCII.
	 */
	public function testStringLengthsCountBytes() : void{
		$name = "Café";
		$written = (new ServerData(serverName: $name, protocol: 2181, version: "1.26.50", levelName: ""))->write();

		//version, then the varint length of the UTF-8 bytes
		self::assertSame(strlen($name), 5);
		self::assertSame(chr(5), substr($written, 1, 1));
		self::assertSame($name, ServerData::read($written)->serverName);
	}

	public function testRoundTripPreservesEveryField() : void{
		$data = new ServerData(
			serverName: "a host",
			protocol: 800,
			version: "1.21.100",
			levelName: "a world",
			gameType: GameType::CREATIVE,
			playerCount: 123,
			maxPlayerCount: 200,
			editorWorld: true,
			hardcore: true,
			acceptsOnlineAuth: false,
			acceptsSelfSignedAuth: false,
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
		$bytes = hex2bin("07" . "00" . "00" . "00" . "00" . "00" . "00" . "10" . "00000000" . "00" . "08");
		self::assertIsString($bytes);

		$this->expectException(DiscoveryException::class);
		$this->expectExceptionMessage("Unknown game type 8");
		ServerData::read($bytes);
	}

	/** Empty names are legal, and produce a one-byte length of zero. */
	public function testEmptyNamesAreAllowed() : void{
		$data = new ServerData(serverName: "", protocol: 2181, version: "1.26.50", levelName: "");
		$read = ServerData::read($data->write());

		self::assertSame("", $read->serverName);
		self::assertSame("", $read->levelName);
		self::assertSame("00", substr(bin2hex($data->write()), 2, 2));
	}
}

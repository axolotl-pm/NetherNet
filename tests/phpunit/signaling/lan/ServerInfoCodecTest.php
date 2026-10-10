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

namespace pocketmine\nethernet\signaling\lan;

use PHPUnit\Framework\TestCase;
use pocketmine\nethernet\info\GameType;
use pocketmine\nethernet\info\ServerInfo;
use pocketmine\nethernet\signaling\lan\packet\DiscoveryException;
use function bin2hex;
use function chr;
use function count;
use function hex2bin;
use function strlen;
use function substr;

final class ServerInfoCodecTest extends TestCase{

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

	private static function reference() : ServerInfo{
		return new ServerInfo(
			serverName: "server",
			protocol: 2181,
			gameVersion: "1.26.50",
			levelName: "world",
			gameType: GameType::ADVENTURE,
			playerCount: 1,
			maxPlayerCount: 8,
			editorWorld: false,
			hardcore: true,
			acceptsOnlineAuth: true,
			acceptsSelfSignedAuth: true,
			nonce: "nonce"
		);
	}

	public function testMatchesTheReferenceEncoding() : void{
		self::assertSame(self::REFERENCE, bin2hex(ServerInfoCodec::encode(self::reference())));
		self::assertSame(38, strlen(ServerInfoCodec::encode(self::reference())));
	}

	public function testReferenceEncodingIsReadBack() : void{
		$bytes = hex2bin(self::REFERENCE);
		self::assertIsString($bytes);

		$data = ServerInfoCodec::decode($bytes);

		self::assertSame("server", $data->serverName);
		self::assertSame(2181, $data->protocol);
		self::assertSame("1.26.50", $data->gameVersion);
		self::assertSame("world", $data->levelName);
		self::assertSame(GameType::ADVENTURE, $data->gameType);
		self::assertSame(1, $data->playerCount);
		self::assertSame(8, $data->maxPlayerCount);
		self::assertFalse($data->editorWorld);
		self::assertTrue($data->hardcore);
		self::assertTrue($data->acceptsOnlineAuth);
		self::assertTrue($data->acceptsSelfSignedAuth);
		self::assertSame("nonce", $data->nonce);
	}

	/**
	 * String lengths count bytes, not characters. Using a character count truncates
	 * every world whose name is not plain ASCII.
	 */
	public function testStringLengthsCountBytes() : void{
		$name = "Café";
		$written = ServerInfoCodec::encode(new ServerInfo(serverName: $name, protocol: 2181, gameVersion: "1.26.50", levelName: ""));

		//version, then the varint length of the UTF-8 bytes
		self::assertSame(strlen($name), 5);
		self::assertSame(chr(5), substr($written, 1, 1));
		self::assertSame($name, ServerInfoCodec::decode($written)->serverName);
	}

	/**
	 * A host may carry its server info across threads in this encoding, so a field it left out would be lost
	 * without a sound. A field added to ServerInfo fails here until it is given a value below and survives the trip.
	 */
	public function testRoundTripPreservesEveryField() : void{
		$fields = [
			"serverName" => "a host",
			"protocol" => 800,
			"gameVersion" => "1.21.100",
			"levelName" => "a world",
			"gameType" => GameType::CREATIVE,
			"playerCount" => 123,
			"maxPlayerCount" => 200,
			"editorWorld" => true,
			"hardcore" => true,
			"acceptsOnlineAuth" => false,
			"acceptsSelfSignedAuth" => false,
			"nonce" => "abc"
		];
		self::assertCount(count($fields), (new \ReflectionMethod(ServerInfo::class, "__construct"))->getParameters());
		$data = new ServerInfo(...$fields);

		self::assertEquals($data, ServerInfoCodec::decode(ServerInfoCodec::encode($data)));
	}

	/** The layout changes between versions, so an older one cannot be read at all. */
	public function testWrongVersionIsRejected() : void{
		$bytes = ServerInfoCodec::encode(self::reference());

		$this->expectException(DiscoveryException::class);
		ServerInfoCodec::decode(chr(4) . substr($bytes, 1));
	}

	public function testTrailingBytesAreRejected() : void{
		$this->expectException(DiscoveryException::class);
		ServerInfoCodec::decode(ServerInfoCodec::encode(self::reference()) . "\x00");
	}

	public function testTruncatedAdvertIsRejected() : void{
		$bytes = ServerInfoCodec::encode(self::reference());

		$this->expectException(DiscoveryException::class);
		ServerInfoCodec::decode(substr($bytes, 0, 10));
	}

	public function testUnknownGameTypeIsRejected() : void{
		$bytes = hex2bin("07" . "00" . "00" . "00" . "00" . "00" . "00" . "10" . "00000000" . "00" . "08");
		self::assertIsString($bytes);

		$this->expectException(DiscoveryException::class);
		$this->expectExceptionMessage("Unknown game type 8");
		ServerInfoCodec::decode($bytes);
	}
}

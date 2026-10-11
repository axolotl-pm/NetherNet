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

namespace pocketmine\nethernet\info;

use PHPUnit\Framework\TestCase;
use function bin2hex;
use function count;

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
	}

	/**
	 * A host may carry its server info across threads in this encoding, so a field it left out would be lost
	 * without a sound. A field added to ServerInfo fails here until it is given a value below and survives the trip.
	 */
	public function testRoundTripPreservesEveryField() : void{
		$fields = [
			"serverName" => "a hôst",
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
}

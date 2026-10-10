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

namespace pocketmine\nethernet\signaling\http;

use PHPUnit\Framework\TestCase;
use pocketmine\nethernet\info\GameType;
use pocketmine\nethernet\info\ServerInfo;
use function json_decode;
use const JSON_THROW_ON_ERROR;

final class ServerInfoJsonTest extends TestCase{

	public function testJsonUsesTheMemberNamesTheClientReads() : void{
		$info = new ServerInfo("Dedicated Server", 800, "1.21.100", "Bedrock level", GameType::CREATIVE, 3, 20);

		self::assertSame([
			"name" => "Dedicated Server",
			"protocol" => 800,
			"version" => "1.21.100",
			"level" => "Bedrock level",
			"players" => 3,
			"maxPlayers" => 20,
			"gameType" => 1
		], json_decode(ServerInfoJson::encode($info), true, 512, JSON_THROW_ON_ERROR));
	}

	/**
	 * Server names are routinely not written in plain ASCII. Escaping them would still
	 * decode correctly, but it makes the one thing an operator looks for in a
	 * capture unreadable.
	 */
	public function testNonAsciiNamesSurviveAsThemselves() : void{
		$json = ServerInfoJson::encode(new ServerInfo("Café Server / #1", 800, "1.21.100", "Lobby"));

		self::assertStringContainsString('"name":"Café Server / #1"', $json);
		self::assertStringContainsString('"level":"Lobby"', $json);
	}
}

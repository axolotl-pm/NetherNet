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

use pocketmine\nethernet\info\ServerInfo;
use function json_encode;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Body of a capability probe (`GET /v1/join`) response.
 */
final class ServerInfoJson{

	private function __construct(){
		//NOOP
	}

	/**
	 * @throws \JsonException
	 */
	public static function encode(ServerInfo $info) : string{
		return json_encode([
			"name" => $info->serverName,
			"protocol" => $info->protocol,
			"version" => $info->gameVersion,
			"level" => $info->levelName,
			"players" => $info->playerCount,
			"maxPlayers" => $info->maxPlayerCount,
			"gameType" => $info->gameType->value
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}

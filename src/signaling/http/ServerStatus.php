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

use pocketmine\nethernet\discovery\GameType;
use function count;
use function ctype_digit;
use function explode;
use function json_encode;
use function ltrim;
use function str_starts_with;
use function strtolower;
use function trim;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Server status metadata returned in response to HTTP capability probes (`GET /v1/join`).
 */
final class ServerStatus{

	public function __construct(
		public readonly string $serverName,
		public readonly int $protocol,
		public readonly string $version,
		public readonly string $levelName,
		public readonly int $playerCount = 0,
		public readonly int $maxPlayerCount = 0,
		public readonly GameType $gameType = GameType::SURVIVAL
	){}

	/**
	 * Parses server status from a standard semicolon-separated RakNet pong string.
	 */
	public static function fromPongData(string $pong) : ?self{
		$fields = explode(";", $pong);
		if(count($fields) < 9){
			return null;
		}

		return new self(
			serverName: $fields[1],
			protocol: self::parseCount($fields[2]),
			version: $fields[3],
			levelName: $fields[7],
			playerCount: self::parseCount($fields[4]),
			maxPlayerCount: self::parseCount($fields[5]),
			gameType: self::parseGameType($fields[8]) ?? GameType::SURVIVAL
		);
	}

	private static function parseGameType(string $name) : ?GameType{
		return match(strtolower(trim($name))){
			"survival" => GameType::SURVIVAL,
			"creative" => GameType::CREATIVE,
			"adventure" => GameType::ADVENTURE,
			default => null
		};
	}

	private static function parseCount(string $value) : int{
		$value = trim($value);
		$digits = str_starts_with($value, "-") ? "" : ltrim($value, "+");

		return ctype_digit($digits) ? (int) $digits : 0;
	}

	/**
	 * Serializes the status object into JSON.
	 */
	public function toJson() : string{
		return json_encode([
			"name" => $this->serverName,
			"protocol" => $this->protocol,
			"version" => $this->version,
			"level" => $this->levelName,
			"players" => $this->playerCount,
			"maxPlayers" => $this->maxPlayerCount,
			"gameType" => $this->gameType->value
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}

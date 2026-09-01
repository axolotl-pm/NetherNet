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
use pocketmine\nethernet\discovery\GameType;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * What a client is told about the host before it decides to join.
 *
 * The source is the RakNet status line a Bedrock host already publishes, so most
 * of what matters here is which of its fields carry over and what happens to the
 * ones that do not parse. None of them is worth refusing to answer a probe over:
 * a wrong player count draws a wrong number, while no answer at all makes the
 * host look like it does not speak NetherNet.
 */
final class ServerStatusTest extends TestCase{

	private const PONG = "MCPE;Dedicated Server;800;1.21.100;3;20;13253860892328930865;Bedrock level;Creative;1;19132;19133;";

	public function testFieldsTheCardNeedsAreReadFromThePongLine() : void{
		$status = ServerStatus::fromPongData(self::PONG);

		self::assertNotNull($status);
		self::assertSame("Dedicated Server", $status->serverName);
		self::assertSame(800, $status->protocol);
		self::assertSame("1.21.100", $status->version);
		self::assertSame("Bedrock level", $status->levelName);
		self::assertSame(3, $status->playerCount);
		self::assertSame(20, $status->maxPlayerCount);
		self::assertSame(GameType::CREATIVE, $status->gameType);
	}

	/**
	 * A host that has not finished starting up publishes a line with nothing in
	 * it. Reading index 8 out of that would be reading past the end.
	 */
	public function testLineTooShortToReadHasNoStatus() : void{
		self::assertNull(ServerStatus::fromPongData("MCPE;Dedicated Server;800;1.21.100;3;20;123;Bedrock level"));
		self::assertNull(ServerStatus::fromPongData(""));
	}

	/**
	 * Spectator has no counterpart on the card, and neither does anything a future
	 * version adds. Falling back beats refusing the whole status over one field.
	 */
	public function testGameModeWithNoCounterpartFallsBackToSurvival() : void{
		$status = ServerStatus::fromPongData("MCPE;a;800;1.21.100;0;10;123;level;Spectator;6;19132;19133;");

		self::assertNotNull($status);
		self::assertSame(GameType::SURVIVAL, $status->gameType);
	}

	/** Game modes are written capitalised on the wire, and read either way. */
	public function testGameModeIsReadWithoutRegardToCase() : void{
		$status = ServerStatus::fromPongData("MCPE;a;800;1.21.100;0;10;123;level; adventure ;2;19132;19133;");

		self::assertNotNull($status);
		self::assertSame(GameType::ADVENTURE, $status->gameType);
	}

	/**
	 * A count that is not a number only ever decides what a player reads before
	 * joining, so it becomes zero rather than costing the host its whole card.
	 */
	public function testCountsThatAreNotNumbersBecomeZero() : void{
		$status = ServerStatus::fromPongData("MCPE;a;;1.21.100;lots;-4;123;level;Survival;0;19132;19133;");

		self::assertNotNull($status);
		self::assertSame(0, $status->protocol);
		self::assertSame(0, $status->playerCount);
		self::assertSame(0, $status->maxPlayerCount);
	}

	public function testJsonUsesTheMemberNamesTheClientReads() : void{
		$status = ServerStatus::fromPongData(self::PONG);

		self::assertNotNull($status);
		self::assertSame([
			"name" => "Dedicated Server",
			"protocol" => 800,
			"version" => "1.21.100",
			"level" => "Bedrock level",
			"players" => 3,
			"maxPlayers" => 20,
			"gameType" => 1
		], json_decode($status->toJson(), true, 512, JSON_THROW_ON_ERROR));
	}

	/**
	 * Server names are routinely not written in plain ASCII. Escaping them would still
	 * decode correctly, but it makes the one thing an operator looks for in a
	 * capture unreadable.
	 */
	public function testNonAsciiNamesSurviveAsThemselves() : void{
		$status = new ServerStatus("Café Server / #1", 800, "1.21.100", "Lobby");

		self::assertStringContainsString('"name":"Café Server / #1"', $status->toJson());
		self::assertStringContainsString('"level":"Lobby"', $status->toJson());
	}
}

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

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use function count;
use function ctype_digit;
use function explode;
use function strlen;
use function strtolower;
use function trim;

/**
 * Server LAN discovery advertisement payload (version 6).
 */
final class ServerData{

	public const VERSION = 6;

	public const CONNECTION_TYPE_NETHERNET = 4;

	/**
	 * @param string $nonce Optional verification nonce echoed back in client Login ClientData.
	 */
	public function __construct(
		public readonly string $serverName,
		public readonly string $levelName,
		public readonly GameType $gameType = GameType::SURVIVAL,
		public readonly int $playerCount = 0,
		public readonly int $maxPlayerCount = 0,
		public readonly bool $editorWorld = false,
		public readonly bool $hardcore = false,
		public readonly bool $acceptsOnlineAuth = true,
		public readonly bool $acceptsSelfSignedAuth = true,
		public readonly string $nonce = "",
		public readonly TransportLayer $transportLayer = TransportLayer::NETHERNET,
		public readonly int $connectionType = self::CONNECTION_TYPE_NETHERNET
	){}

	/**
	 * Parses a ServerData payload from a semicolon-separated RakNet pong string.
	 */
	public static function fromPongData(string $pong, ?TransportLayer $transportLayer = null) : ?self{
		$fields = explode(";", $pong);
		if(count($fields) < 9){
			return null;
		}

		return new self(
			serverName: $fields[1],
			levelName: $fields[7],
			gameType: self::parseGameType($fields[8]) ?? GameType::SURVIVAL,
			playerCount: self::parseCount($fields[4]),
			maxPlayerCount: self::parseCount($fields[5]),
			transportLayer: $transportLayer ?? TransportLayer::NETHERNET
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

		return ctype_digit($value) ? (int) $value : 0;
	}

	/**
	 * @throws DiscoveryException
	 */
	public static function read(string $data) : self{
		$in = new ByteBufferReader($data);

		try{
			$version = Byte::readUnsigned($in);
			if($version !== self::VERSION){
				throw new DiscoveryException("Expected advert version " . self::VERSION . ", got $version");
			}

			$serverName = self::readString($in);
			$levelName = self::readString($in);

			$rawGameType = VarInt::readSignedInt($in);
			$gameType = GameType::tryFrom($rawGameType);
			if($gameType === null){
				throw new DiscoveryException("Unknown game type $rawGameType");
			}

			$playerCount = LE::readSignedInt($in);
			$maxPlayerCount = LE::readSignedInt($in);
			$editorWorld = Byte::readUnsigned($in) !== 0;
			$hardcore = Byte::readUnsigned($in) !== 0;
			$acceptsOnlineAuth = Byte::readUnsigned($in) !== 0;
			$acceptsSelfSignedAuth = Byte::readUnsigned($in) !== 0;
			$nonce = self::readString($in);

			$rawTransport = VarInt::readSignedInt($in);
			$transportLayer = TransportLayer::tryFrom($rawTransport);
			if($transportLayer === null){
				throw new DiscoveryException("Unknown transport layer $rawTransport");
			}

			$connectionType = VarInt::readSignedInt($in);

			if($in->getUnreadLength() !== 0){
				throw new DiscoveryException("Advert has " . $in->getUnreadLength() . " bytes left over");
			}
		}catch(DataDecodeException $e){
			throw new DiscoveryException("Advert ended too early: " . $e->getMessage(), 0, $e);
		}

		return new self(
			$serverName,
			$levelName,
			$gameType,
			$playerCount,
			$maxPlayerCount,
			$editorWorld,
			$hardcore,
			$acceptsOnlineAuth,
			$acceptsSelfSignedAuth,
			$nonce,
			$transportLayer,
			$connectionType
		);
	}

	public function write() : string{
		$out = new ByteBufferWriter();

		Byte::writeUnsigned($out, self::VERSION);
		self::writeString($out, $this->serverName);
		self::writeString($out, $this->levelName);
		VarInt::writeSignedInt($out, $this->gameType->value);
		LE::writeSignedInt($out, $this->playerCount);
		LE::writeSignedInt($out, $this->maxPlayerCount);
		Byte::writeUnsigned($out, $this->editorWorld ? 1 : 0);
		Byte::writeUnsigned($out, $this->hardcore ? 1 : 0);
		Byte::writeUnsigned($out, $this->acceptsOnlineAuth ? 1 : 0);
		Byte::writeUnsigned($out, $this->acceptsSelfSignedAuth ? 1 : 0);
		self::writeString($out, $this->nonce);
		VarInt::writeSignedInt($out, $this->transportLayer->value);
		VarInt::writeSignedInt($out, $this->connectionType);

		return $out->getData();
	}

	/**
	 * @throws DataDecodeException
	 */
	private static function readString(ByteBufferReader $in) : string{
		return $in->readByteArray(VarInt::readUnsignedInt($in));
	}

	private static function writeString(ByteBufferWriter $out, string $value) : void{
		VarInt::writeUnsignedInt($out, strlen($value));
		$out->writeByteArray($value);
	}
}

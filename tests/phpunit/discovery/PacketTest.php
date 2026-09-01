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
use pocketmine\nethernet\discovery\packet\MessagePacket;
use pocketmine\nethernet\discovery\packet\PacketSerializer;
use pocketmine\nethernet\discovery\packet\PacketType;
use pocketmine\nethernet\discovery\packet\RequestPacket;
use pocketmine\nethernet\discovery\packet\ResponsePacket;
use function bin2hex;
use function chr;
use function hash;
use function hash_hmac;
use function hex2bin;
use function openssl_decrypt;
use function ord;
use function pack;
use function sprintf;
use function str_repeat;
use function strlen;
use function substr;
use const OPENSSL_RAW_DATA;

final class PacketTest extends TestCase{

	/** Chosen so every byte of the little-endian sender field is distinct. */
	private const SENDER_ID = 0x1020304050607080;

	/**
	 * Everything on this network derives the same key from the same published
	 * constant. If this value drifts, nothing interoperates and the only symptom
	 * is that no world ever appears in the list.
	 */
	public function testKeyMatchesTheOneEveryImplementationDerives() : void{
		self::assertSame(
			"eed4c37861936c6ebb91ab9d195c1b489de46362d3bb8e30d8aab08bf217e6f3",
			bin2hex(DiscoveryCipher::key())
		);
	}

	/**
	 * A request is the smallest datagram there is, and its whole plaintext is
	 * header. Pinning the bytes catches a header field changing width or order,
	 * which would otherwise show up only as a peer that never answers.
	 */
	public function testRequestPlaintextMatchesTheReferenceLayout() : void{
		$frame = PacketSerializer::encode(new RequestPacket(), self::SENDER_ID);

		//32 bytes of checksum, then 20 bytes of plaintext padded to two blocks
		self::assertSame(64, strlen($frame));

		$plaintext = openssl_decrypt(substr($frame, 32), "aes-256-ecb", DiscoveryCipher::key(), OPENSSL_RAW_DATA, "");
		self::assertIsString($plaintext);
		self::assertSame("1400" . "0000" . "8070605040302010" . str_repeat("00", 8), bin2hex($plaintext));
	}

	/** The length field counts the whole plaintext, including itself. */
	public function testLengthPrefixCountsItself() : void{
		$frame = PacketSerializer::encode(new RequestPacket(), self::SENDER_ID);
		$plaintext = openssl_decrypt(substr($frame, 32), "aes-256-ecb", DiscoveryCipher::key(), OPENSSL_RAW_DATA, "");

		self::assertIsString($plaintext);
		self::assertSame(pack("v", 20), substr($plaintext, 0, 2));
		self::assertSame(20, strlen($plaintext));
	}

	/**
	 * The checksum covers the plaintext, not the ciphertext. Getting this backwards
	 * produces datagrams that look right and are rejected by every real peer.
	 */
	public function testChecksumIsTakenOverThePlaintext() : void{
		$frame = PacketSerializer::encode(new RequestPacket(), self::SENDER_ID);
		$plaintext = openssl_decrypt(substr($frame, 32), "aes-256-ecb", DiscoveryCipher::key(), OPENSSL_RAW_DATA, "");

		self::assertIsString($plaintext);
		self::assertSame(
			bin2hex(hash_hmac("sha256", $plaintext, DiscoveryCipher::key(), true)),
			bin2hex(substr($frame, 0, 32))
		);
	}

	public function testRequestSurvivesTheRoundTrip() : void{
		[$packet, $senderId] = PacketSerializer::decode(PacketSerializer::encode(new RequestPacket(), self::SENDER_ID));

		self::assertInstanceOf(RequestPacket::class, $packet);
		self::assertSame(PacketType::REQUEST, $packet->getType());
		self::assertSame(self::SENDER_ID, $senderId);
	}

	/**
	 * A response hex-encodes its payload while a message does not. The asymmetry is
	 * in the protocol, and treating both alike breaks one of them.
	 */
	public function testResponsePayloadIsHexEncodedOnTheWire() : void{
		$frame = PacketSerializer::encode(new ResponsePacket("\x01\x02\xab"), self::SENDER_ID);
		$plaintext = openssl_decrypt(substr($frame, 32), "aes-256-ecb", DiscoveryCipher::key(), OPENSSL_RAW_DATA, "");
		self::assertIsString($plaintext);

		//header is 20 bytes, then a uint32 length of 6 hex characters
		self::assertSame(pack("V", 6), substr($plaintext, 20, 4));
		self::assertSame("0102ab", substr($plaintext, 24, 6));
	}

	public function testMessagePayloadIsSentRaw() : void{
		$frame = PacketSerializer::encode(new MessagePacket(0xAABBCCDD, "CONNECTREQUEST 42 hi"), self::SENDER_ID);
		$plaintext = openssl_decrypt(substr($frame, 32), "aes-256-ecb", DiscoveryCipher::key(), OPENSSL_RAW_DATA, "");
		self::assertIsString($plaintext);

		self::assertSame(pack("P", 0xAABBCCDD), substr($plaintext, 20, 8));
		self::assertSame(pack("V", 20), substr($plaintext, 28, 4));
		self::assertSame("CONNECTREQUEST 42 hi", substr($plaintext, 32, 20));
	}

	public function testResponseSurvivesTheRoundTrip() : void{
		$payload = "\x00\xff\x10binary\x00";
		[$packet] = PacketSerializer::decode(PacketSerializer::encode(new ResponsePacket($payload), self::SENDER_ID));

		self::assertInstanceOf(ResponsePacket::class, $packet);
		self::assertSame($payload, $packet->applicationData);
	}

	public function testMessageSurvivesTheRoundTrip() : void{
		[$packet] = PacketSerializer::decode(PacketSerializer::encode(new MessagePacket(0xAABBCCDD, "hello there"), self::SENDER_ID));

		self::assertInstanceOf(MessagePacket::class, $packet);
		self::assertSame(0xAABBCCDD, $packet->recipientId);
		self::assertSame("hello there", $packet->data);
	}

	/**
	 * Network ids are unsigned 64 bit, so the top half of the range lands on
	 * negative PHP integers. They still have to round-trip untouched.
	 */
	public function testSenderIdAboveTheSignedRangeRoundTrips() : void{
		$senderId = -1; //0xFFFFFFFFFFFFFFFF as PHP sees it
		[, $decoded] = PacketSerializer::decode(PacketSerializer::encode(new RequestPacket(), $senderId));

		self::assertSame($senderId, $decoded);
		self::assertSame("18446744073709551615", sprintf("%u", $decoded));
	}

	public function testTamperedFrameIsRejected() : void{
		$frame = PacketSerializer::encode(new RequestPacket(), self::SENDER_ID);
		$tampered = substr($frame, 0, 40) . chr(ord($frame[40]) ^ 0xff) . substr($frame, 41);

		$this->expectException(DiscoveryException::class);
		PacketSerializer::decode($tampered);
	}

	/**
	 * @dataProvider unreadableFrameProvider
	 */
	public function testUnreadableFrameIsRejected(string $frame) : void{
		$this->expectException(DiscoveryException::class);
		PacketSerializer::decode($frame);
	}

	/**
	 * @return string[][]
	 * @phpstan-return array<string, array{string}>
	 */
	public static function unreadableFrameProvider() : array{
		return [
			"empty" => [""],
			"checksum only" => [str_repeat("\x00", 32)],
			"ciphertext is not a whole block" => [str_repeat("\x00", 32 + 17)],
			"random noise" => [str_repeat("\x41", 64)]
		];
	}

	/** An unknown type is a newer peer, not something to guess at. */
	public function testUnknownPacketTypeIsRejected() : void{
		$plaintext = hex2bin("1400" . "ff00" . "8070605040302010" . str_repeat("00", 8));
		self::assertIsString($plaintext);

		$this->expectException(DiscoveryException::class);
		PacketSerializer::decode(DiscoveryCipher::seal($plaintext));
	}

	/**
	 * Trailing bytes mean the body was misread, so the datagram is dropped rather
	 * than half-believed.
	 */
	public function testTrailingBytesAreRejected() : void{
		$plaintext = hex2bin("1600" . "0000" . "8070605040302010" . str_repeat("00", 8) . "abcd");
		self::assertIsString($plaintext);

		$this->expectException(DiscoveryException::class);
		PacketSerializer::decode(DiscoveryCipher::seal($plaintext));
	}

	/**
	 * Vanilla writes more message data than its own length prefix declares, with
	 * the rest of the signal continuing straight after the declared range.
	 *
	 * Stopping at the prefix would cut a CONNECTREQUEST in half and then drop the
	 * whole datagram over the leftover bytes, so a real client would never get
	 * past the offer.
	 */
	public function testMessageDataPastTheDeclaredLengthIsStillRead() : void{
		$declared = "CONNECTREQUEST 1 v=0\r\n";
		$rest = "a=candidate:1 1 udp 1 192.168.1.2 50000 typ host\r\n";

		$body =
			pack("v", PacketType::MESSAGE->value) .
			pack("P", self::SENDER_ID) .
			str_repeat("\x00", 8) .
			pack("P", 2) .
			pack("V", strlen($declared)) .
			$declared .
			$rest;

		[$packet] = PacketSerializer::decode(DiscoveryCipher::seal(pack("v", strlen($body) + 2) . $body));

		self::assertInstanceOf(MessagePacket::class, $packet);
		self::assertSame($declared . $rest, $packet->data);
	}

	/**
	 * Vanilla counts the whole plaintext in the length prefix while older builds
	 * counted only what followed it. The field is ignored for exactly this reason,
	 * so both have to decode.
	 */
	public function testLengthPrefixIsNotTrusted() : void{
		foreach(["1400", "1200", "0000", "ffff"] as $prefix){
			$plaintext = hex2bin($prefix . "0000" . "8070605040302010" . str_repeat("00", 8));
			self::assertIsString($plaintext);

			[$packet] = PacketSerializer::decode(DiscoveryCipher::seal($plaintext));
			self::assertInstanceOf(RequestPacket::class, $packet);
		}
	}

	/** Padding is always added, even when the plaintext is already block aligned. */
	public function testBlockAlignedPlaintextStillGetsAWholePaddingBlock() : void{
		$aligned = str_repeat("\x00", 32);

		self::assertSame(48, strlen(DiscoveryCipher::seal($aligned)) - DiscoveryCipher::CHECKSUM_SIZE);
		self::assertSame($aligned, DiscoveryCipher::open(DiscoveryCipher::seal($aligned)));
	}

	public function testKeyDerivationInputIsLittleEndian() : void{
		$input = hex2bin("efbeadde00000000");
		self::assertIsString($input);

		self::assertSame(bin2hex(hash("sha256", $input, true)), bin2hex(DiscoveryCipher::key()));
	}
}

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

namespace pocketmine\nethernet\sdp;

use PHPUnit\Framework\TestCase;
use function implode;
use function str_replace;

final class SessionDescriptionTest extends TestCase{

	/**
	 * @param string[] $extraSession
	 * @phpstan-param list<string> $extraSession
	 * @param string[] $extraMedia
	 * @phpstan-param list<string> $extraMedia
	 */
	private static function build(array $extraSession = [], array $extraMedia = []) : string{
		return implode("\r\n", [
			"v=0",
			"o=- 1 2 IN IP4 127.0.0.1",
			"s=-",
			"t=0 0",
			"a=group:BUNDLE 0",
			...$extraSession,
			"m=application 9 UDP/DTLS/SCTP webrtc-datachannel",
			"c=IN IP4 0.0.0.0",
			"a=ice-ufrag:abcd",
			"a=ice-pwd:0123456789abcdef",
			"a=fingerprint:sha-256 AA:BB:CC:DD",
			"a=setup:active",
			"a=mid:0",
			"a=sctp-port:5000",
			"a=max-message-size:262144",
			...$extraMedia
		]) . "\r\n";
	}

	public function testParseAcceptsBareLineFeeds() : void{
		$crlf = SessionDescription::parse(self::build());
		$lf = SessionDescription::parse(str_replace("\r\n", "\n", self::build()));

		self::assertSame($crlf->toString(), $lf->toString());
	}

	public function testEmptyDescriptionIsRefused() : void{
		$this->expectException(SdpException::class);
		SessionDescription::parse("");
	}

	/**
	 * A description with more lines than any real offer carries is refused rather
	 * than parsed, so a hostile body cannot be turned into a huge line array the
	 * attribute lookups then scan and slice repeatedly.
	 */
	public function testDescriptionWithTooManyLinesIsRefused() : void{
		$flood = [];
		for($i = 0; $i <= SessionDescription::MAX_LINES; $i++){
			$flood[] = "a=candidate:$i";
		}

		$this->expectException(SdpException::class);
		SessionDescription::parse(self::build(extraMedia: $flood));
	}

	/** Output always terminates the last line, which some parsers require. */
	public function testToStringTerminatesEveryLine() : void{
		self::assertStringEndsWith("a=max-message-size:262144\r\n", SessionDescription::parse(self::build())->toString());
	}

	/**
	 * The candidate count is what the negotiator caps, since the native stack
	 * resolves a hostname candidate on a thread of its own. Only candidate lines
	 * count, wherever they sit, and other attributes do not.
	 */
	public function testCountCandidates() : void{
		self::assertSame(0, SessionDescription::parse(self::build())->countCandidates());

		$description = SessionDescription::parse(self::build(
			extraSession: ["a=candidate:1 1 udp 1 1.2.3.4 5000 typ host"],
			extraMedia: [
				"a=candidate:2 1 udp 1 5.6.7.8 5001 typ host",
				"a=candidate:3 1 udp 1 host.example 5002 typ host",
				"a=end-of-candidates"
			]
		));
		self::assertSame(3, $description->countCandidates());
	}

	/**
	 * Adding then removing the assertion has to leave the description exactly as
	 * it was. The peer's copy is what its signature covers, so any stray byte left
	 * behind breaks verification later.
	 */
	public function testStrippingIdentityIsTheInverseOfAddingIt() : void{
		$original = SessionDescription::parse(self::build());

		self::assertSame(
			$original->toString(),
			$original->withIdentity("Zm9v")->withoutIdentity()->toString()
		);
	}

	public function testIdentityIsPlacedAtSessionLevel() : void{
		$withIdentity = SessionDescription::parse(self::build())->withIdentity("Zm9v");

		self::assertSame("Zm9v", $withIdentity->getIdentity());
		self::assertSame(["Zm9v"], $withIdentity->getSessionAttributeValues("identity"));
		self::assertSame([], $withIdentity->getMediaAttributeValues("identity"));
	}

	/** Applying it twice must not leave two assertions in the description. */
	public function testAddingIdentityTwiceReplacesTheFirst() : void{
		$twice = SessionDescription::parse(self::build())->withIdentity("Zm9v")->withIdentity("YmFy");

		self::assertSame(["YmFy"], $twice->getSessionAttributeValues("identity"));
	}

	/**
	 * An assertion at media level is not one this transport reads, so it must not
	 * be picked up by accident.
	 */
	public function testMediaLevelIdentityIsNotRead() : void{
		$description = SessionDescription::parse(self::build(extraMedia: ["a=identity:Zm9v"]));

		self::assertNull($description->getIdentity());
	}

	/** Stripping still removes it, so nothing unverified reaches the WebRTC stack. */
	public function testMediaLevelIdentityIsStillStripped() : void{
		$description = SessionDescription::parse(self::build(extraMedia: ["a=identity:Zm9v"]));

		self::assertStringNotContainsString("a=identity", $description->withoutIdentity()->toString());
	}

	/**
	 * The reference implementation resolves the fingerprint at media level first,
	 * so signing the session-level one would produce an assertion covering the
	 * wrong certificate.
	 */
	public function testMediaLevelFingerprintWinsOverSessionLevel() : void{
		$description = SessionDescription::parse(self::build(extraSession: ["a=fingerprint:sha-256 11:22"]));

		self::assertSame("AA:BB:CC:DD", $description->getFingerprint()->getDigest());
	}

	public function testSessionLevelFingerprintIsUsedWhenMediaHasNone() : void{
		$sdp = str_replace("a=fingerprint:sha-256 AA:BB:CC:DD\r\n", "", self::build(extraSession: ["a=fingerprint:sha-256 11:22"]));

		self::assertSame("11:22", SessionDescription::parse($sdp)->getFingerprint()->getDigest());
	}

	/**
	 * Two fingerprints leave no way to know which certificate to bind to, and
	 * guessing produces an assertion the peer rejects for no visible reason.
	 */
	public function testAmbiguousFingerprintIsRefused() : void{
		$description = SessionDescription::parse(self::build(extraMedia: ["a=fingerprint:sha-256 11:22"]));

		$this->expectException(SdpException::class);
		$description->getFingerprint();
	}

	public function testValidateAcceptsACompleteDescription() : void{
		$this->expectNotToPerformAssertions();
		SessionDescription::parse(self::build())->validate();
	}

	/**
	 * @dataProvider missingAttributeProvider
	 */
	public function testValidateRefusesADescriptionMissingSomethingRequired(string $line) : void{
		$sdp = str_replace($line . "\r\n", "", self::build());

		$this->expectException(SdpException::class);
		SessionDescription::parse($sdp)->validate();
	}

	/**
	 * @return string[][]
	 * @phpstan-return array<string, array{string}>
	 */
	public static function missingAttributeProvider() : array{
		return [
			"ice-ufrag" => ["a=ice-ufrag:abcd"],
			"ice-pwd" => ["a=ice-pwd:0123456789abcdef"],
			"setup" => ["a=setup:active"],
			"max-message-size" => ["a=max-message-size:262144"],
			"fingerprint" => ["a=fingerprint:sha-256 AA:BB:CC:DD"],
			"media section" => ["m=application 9 UDP/DTLS/SCTP webrtc-datachannel"]
		];
	}

	public function testMaxMessageSizeIsReadFromTheMediaSection() : void{
		self::assertSame(262144, SessionDescription::parse(self::build())->getMaxMessageSize());
	}

	/**
	 * One byte of every message is the fragment counter, so a peer advertising one
	 * byte has left no room for a payload and could never be sent anything. The
	 * same goes for a value that is not a number or is wider than the attribute.
	 *
	 * @dataProvider unusableMaxMessageSizeProvider
	 */
	public function testValidateRefusesAMaxMessageSizeNothingFitsIn(string $value) : void{
		$sdp = str_replace("a=max-message-size:262144", "a=max-message-size:" . $value, self::build());

		$this->expectException(SdpException::class);
		SessionDescription::parse($sdp)->validate();
	}

	/**
	 * @return string[][]
	 * @phpstan-return array<string, array{string}>
	 */
	public static function unusableMaxMessageSizeProvider() : array{
		return [
			"only the counter fits" => ["1"],
			"nothing fits" => ["0"],
			"not a number" => ["262144bytes"],
			"negative" => ["-1"],
			"wider than the attribute" => ["4294967296"]
		];
	}

	/** NetherNet always bundles onto one media section. */
	public function testValidateRefusesASecondMediaSection() : void{
		$description = SessionDescription::parse(self::build() . "m=audio 9 UDP/DTLS/SCTP webrtc-datachannel\r\n");

		$this->expectException(SdpException::class);
		$description->validate();
	}
}

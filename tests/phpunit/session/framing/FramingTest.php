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

namespace pocketmine\nethernet\session\framing;

use PHPUnit\Framework\TestCase;
use function chr;
use function count;
use function str_repeat;
use function strlen;
use function substr;

final class FramingTest extends TestCase{

	private function assemble(Assembler $assembler, string ...$segments) : ?string{
		$result = null;
		foreach($segments as $segment){
			$result = $assembler->accept($segment);
		}

		return $result;
	}

	/**
	 * The counter has to fall to zero on the last segment. A receiver that trusted
	 * a length prefix instead could be told to allocate anything, which is why the
	 * protocol counts down rather than announcing a total.
	 */
	public function testFinalSegmentIsMarkedWithZero() : void{
		$segments = (new Segmenter(4))->segment("abcdefghij");

		self::assertCount(3, $segments);
		self::assertSame(chr(2) . "abcd", $segments[0]);
		self::assertSame(chr(1) . "efgh", $segments[1]);
		self::assertSame(chr(0) . "ij", $segments[2]);
	}

	public function testRoundTripAcrossManySegments() : void{
		$payload = str_repeat("payload", 500);
		$segmenter = new Segmenter(16);
		$assembler = new Assembler(true);

		$segments = $segmenter->segment($payload);
		self::assertGreaterThan(1, count($segments));
		self::assertSame($payload, $this->assemble($assembler, ...$segments));
	}

	public function testUnfragmentedMessageIsJustAZeroByteAndThePayload() : void{
		$segments = (new Segmenter())->segment("hello");

		self::assertSame(["\x00hello"], $segments);
		self::assertSame("hello", (new Assembler(true))->accept($segments[0]));
	}

	/**
	 * The framing cannot express an empty message: a lone counter byte would
	 * arrive as a zero-length payload, which means nothing here. Sending nothing
	 * is the only honest answer.
	 */
	public function testEmptyPayloadProducesNoSegments() : void{
		self::assertSame([], (new Segmenter())->segment(""));
	}

	/**
	 * A payload of exactly the segment size must not spill into a second segment.
	 * Getting this boundary wrong produces a trailing empty segment that the peer
	 * refuses.
	 */
	public function testPayloadOfExactlyOneSegmentIsNotFragmented() : void{
		$segmenter = new Segmenter(8);

		self::assertFalse($segmenter->isFragmentationRequired("12345678"));
		self::assertCount(1, $segmenter->segment("12345678"));
		self::assertCount(2, $segmenter->segment("123456789"));
	}

	/**
	 * The counter byte could express 256 segments, but vanilla stops at 255, so
	 * that is where the refusal has to be. Framing a 256th segment would produce a
	 * message the peer drops without saying why.
	 */
	public function testPayloadIsRefusedAboveTheSegmentLimitVanillaAccepts() : void{
		$segmenter = new Segmenter(1);

		self::assertCount(Segmenter::MAX_SEGMENTS, $segmenter->segment(str_repeat("x", Segmenter::MAX_SEGMENTS)));

		$this->expectException(FramingException::class);
		$segmenter->segment(str_repeat("x", Segmenter::MAX_SEGMENTS + 1));
	}

	/**
	 * A gap in the countdown means a segment was lost or reordered. Continuing
	 * would splice unrelated bytes together and hand the caller a payload that
	 * never existed, so the message is refused instead.
	 */
	public function testSegmentSkippingACountIsRefused() : void{
		$assembler = new Assembler(true);
		$assembler->accept(chr(3) . "aa");

		$this->expectException(FramingException::class);
		$assembler->accept(chr(1) . "bb");
	}

	/**
	 * SCTP may drop a message on the unreliable channel, so a group of segments
	 * sent over it can never be completed. Accepting the first one would leave the
	 * assembler waiting for a segment that is not coming.
	 */
	public function testUnreliableChannelRefusesFragments() : void{
		$this->expectException(FramingException::class);
		(new Assembler(false))->accept(chr(1) . "aa");
	}

	public function testUnreliableChannelAcceptsWholeMessages() : void{
		self::assertSame("aa", (new Assembler(false))->accept(chr(0) . "aa"));
	}

	public function testSegmentWithoutPayloadIsRefused() : void{
		$this->expectException(FramingException::class);
		(new Assembler(true))->accept(chr(0));
	}

	/**
	 * The cap exists to stop a peer growing the buffer without bound, so it has to
	 * be enforced while assembling rather than after the last segment arrives.
	 */
	public function testAssemblyStopsAtTheConfiguredPayloadLimit() : void{
		$assembler = new Assembler(true, 4);
		$assembler->accept(chr(1) . "abcd");

		$this->expectException(FramingException::class);
		$assembler->accept(chr(0) . "e");
	}

	/**
	 * A refused segment must not poison the next message. The assembler is reused
	 * for the lifetime of a channel, and a stale buffer would corrupt whatever
	 * came after.
	 */
	public function testAssemblerIsUsableAfterRefusingASegment() : void{
		$assembler = new Assembler(true);
		$assembler->accept(chr(2) . "aa");

		try{
			$assembler->accept(chr(0) . "bb");
			self::fail("Expected the broken countdown to be refused");
		}catch(FramingException){
			//expected
		}

		self::assertFalse($assembler->isAssembling());
		self::assertSame("cc", $assembler->accept(chr(0) . "cc"));
	}

	/**
	 * The advertised SCTP message size includes the counter byte, so the usable
	 * payload is one less. Off by one here means every full-size message is
	 * rejected by the peer.
	 */
	public function testSegmentSizeLeavesRoomForTheCounterByte() : void{
		$segments = (new Segmenter())->segment(str_repeat("x", Segmenter::MAX_SEGMENT_PAYLOAD_SIZE));

		self::assertCount(1, $segments);
		self::assertSame(Segmenter::MAX_SEGMENT_PAYLOAD_SIZE + 1, strlen($segments[0]));
		self::assertSame(262144, strlen($segments[0]));
		self::assertSame(str_repeat("x", 8), substr($segments[0], 1, 8));
	}
}

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

namespace pocketmine\nethernet\utils;

use PHPUnit\Framework\TestCase;

final class AddressBlockTrackerTest extends TestCase{

	public function testBlockLastsUntilItsExpiry() : void{
		$tracker = new AddressBlockTracker();
		$tracker->update(100.0);
		$tracker->block("192.0.2.1", 110.0);

		self::assertTrue($tracker->isBlocked("192.0.2.1"));

		$tracker->update(109.9);
		self::assertTrue($tracker->isBlocked("192.0.2.1"));

		$tracker->update(110.0);
		self::assertFalse($tracker->isBlocked("192.0.2.1"));
	}

	public function testBlockWithoutExpiryLastsUntilUnblocked() : void{
		$tracker = new AddressBlockTracker();
		$tracker->block("192.0.2.1");

		$tracker->update(1.0e12);
		self::assertTrue($tracker->isBlocked("192.0.2.1"));

		$tracker->unblock("192.0.2.1");
		self::assertFalse($tracker->isBlocked("192.0.2.1"));
	}

	/**
	 * A shorter block arriving later must not cut an existing longer one short.
	 */
	public function testShorterBlockDoesNotReplaceALongerOne() : void{
		$tracker = new AddressBlockTracker();
		$tracker->block("192.0.2.1", 500.0);
		$tracker->block("192.0.2.1", 50.0);

		$tracker->update(100.0);
		self::assertTrue($tracker->isBlocked("192.0.2.1"));
	}

	public function testExpiryOfOneBlockLeavesLaterOnesInPlace() : void{
		$tracker = new AddressBlockTracker();
		$tracker->block("192.0.2.1", 10.0);
		$tracker->block("192.0.2.2", 20.0);

		$tracker->update(15.0);
		self::assertFalse($tracker->isBlocked("192.0.2.1"));
		self::assertTrue($tracker->isBlocked("192.0.2.2"));

		$tracker->update(20.0);
		self::assertFalse($tracker->isBlocked("192.0.2.2"));
	}

	/**
	 * The same IPv6 address can be written many ways, and a block has to hold for all of them.
	 */
	public function testEquivalentSpellingsOfAnAddressShareABlock() : void{
		$tracker = new AddressBlockTracker();
		$tracker->block("2001:db8:0:0:0:0:0:1");

		self::assertTrue($tracker->isBlocked("2001:db8::1"));
		self::assertTrue($tracker->isBlocked("[2001:DB8::1]"));

		$tracker->unblock("2001:db8::1");
		self::assertFalse($tracker->isBlocked("2001:db8:0:0:0:0:0:1"));
	}
}

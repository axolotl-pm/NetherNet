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

namespace pocketmine\nethernet;

use PHPUnit\Framework\TestCase;
use pocketmine\nethernet\discovery\FixedServerDataProvider;
use pocketmine\nethernet\discovery\LanSignaling;
use pocketmine\nethernet\discovery\ServerData;
use pocketmine\nethernet\signaling\http\HttpSignaling;
use pocketmine\nethernet\signaling\SignalingException;
use pocketmine\nethernet\signaling\SignalingInterface;
use function count;
use function implode;
use function random_int;

/**
 * A transport with nothing to do has to stay quiet.
 *
 * This is not tidiness. Both transports drain their socket until it reports
 * having nothing left, and "nothing left" is an error code; reading that code
 * from the wrong place makes an idle socket look like a broken one. The symptom
 * is a debug line every few milliseconds forever, and the only thing that
 * catches it is asserting on silence.
 *
 * It bit the accept loop once already: socket_accept() records its error in the
 * global slot rather than against the socket, so checking the socket's slot
 * returned zero and every idle pass was reported as a failure.
 */
final class IdleTransportTest extends TestCase{

	private const IDLE_PASSES = 25;

	private ?SignalingInterface $signaling = null;

	protected function tearDown() : void{
		$this->signaling?->shutdown();
	}

	/**
	 * @phpstan-param \Closure(int) : SignalingInterface $factory
	 */
	private function startOnAFreePort(\Closure $factory) : SignalingInterface{
		for($attempt = 0; $attempt < 25; ++$attempt){
			$signaling = $factory(random_int(20000, 60000));

			try{
				$signaling->start();
			}catch(SignalingException){
				//this machine reserves scattered port ranges; just pick again
				continue;
			}

			return $this->signaling = $signaling;
		}

		self::fail("Could not find a free port to listen on");
	}

	public function testIdleHttpSignalingLogsNothing() : void{
		$logger = new RecordingLogger();
		$signaling = $this->startOnAFreePort(fn(int $port) => new HttpSignaling(
			new FakeNegotiator("answer-sdp"),
			"127.0.0.1",
			$port,
			null,
			$logger
		));

		for($i = 0; $i < self::IDLE_PASSES; ++$i){
			$signaling->tick();
		}

		self::assertSame([], $logger->messages, "an idle listener logged: " . implode(" | ", $logger->messages));
	}

	public function testIdleLanSignalingLogsNothingBeyondItsStartupLine() : void{
		$logger = new RecordingLogger();
		$signaling = $this->startOnAFreePort(fn(int $port) => new LanSignaling(
			new FakeNegotiator("answer-sdp"),
			new FixedServerDataProvider(new ServerData(serverName: "host", levelName: "world")),
			4242,
			"127.0.0.1",
			$port,
			$logger
		));

		//one line is written when the socket comes up, and nothing after it
		$afterStart = count($logger->messages);
		self::assertSame(1, $afterStart);

		for($i = 0; $i < self::IDLE_PASSES; ++$i){
			$signaling->tick();
		}

		self::assertCount($afterStart, $logger->messages, "an idle listener logged: " . implode(" | ", $logger->messages));
	}
}

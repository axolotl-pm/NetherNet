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
use pocketmine\nethernet\info\MutableServerInfoProvider;
use pocketmine\nethernet\info\ServerInfo;
use pocketmine\nethernet\signaling\http\HttpSignaling;
use pocketmine\nethernet\signaling\lan\LanSignaling;
use pocketmine\nethernet\signaling\Signaling;
use pocketmine\nethernet\utils\InternetAddress;
use function count;
use function implode;
use function microtime;

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

	private ?Signaling $signaling = null;

	protected function tearDown() : void{
		$this->signaling?->shutdown();
	}

	private function start(Signaling $signaling, \Logger $logger) : Signaling{
		$signaling->start(TestSignalingContext::create(new FakeNegotiator("answer-sdp"), $logger));

		return $this->signaling = $signaling;
	}

	public function testIdleHttpSignalingLogsNothing() : void{
		$logger = new RecordingLogger();
		$signaling = $this->start(new HttpSignaling(new InternetAddress("127.0.0.1", 0, 4)), $logger);

		for($i = 0; $i < self::IDLE_PASSES; ++$i){
			$signaling->tick(microtime(true));
		}

		self::assertSame([], $logger->messages, "an idle listener logged: " . implode(" | ", $logger->messages));
	}

	public function testIdleLanSignalingLogsNothingBeyondItsStartupLine() : void{
		$logger = new RecordingLogger();
		$signaling = $this->start(new LanSignaling(
			new MutableServerInfoProvider(new ServerInfo(serverName: "host", protocol: 2181, gameVersion: "1.26.50", levelName: "world")),
			4242,
			new InternetAddress("127.0.0.1", 0, 4)
		), $logger);

		//one line is written when the socket comes up, and nothing after it
		$afterStart = count($logger->messages);
		self::assertSame(1, $afterStart);

		for($i = 0; $i < self::IDLE_PASSES; ++$i){
			$signaling->tick(microtime(true));
		}

		self::assertCount($afterStart, $logger->messages, "an idle listener logged: " . implode(" | ", $logger->messages));
	}
}

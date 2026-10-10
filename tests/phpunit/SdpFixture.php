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

use function implode;

final class SdpFixture{

	public const HOST_CANDIDATE = "candidate:1 1 UDP 2122317823 192.168.1.20 51000 typ host";

	private function __construct(){
		//NOOP
	}

	/**
	 * A data channel description complete enough to pass as an offer, or as the local description a transport answers with.
	 *
	 * @param string[] $candidates
	 * @phpstan-param list<string> $candidates
	 */
	public static function description(array $candidates = []) : string{
		$lines = [
			"v=0",
			"o=- 1 2 IN IP4 127.0.0.1",
			"s=-",
			"t=0 0",
			"a=group:BUNDLE 0",
			"m=application 9 UDP/DTLS/SCTP webrtc-datachannel",
			"c=IN IP4 0.0.0.0",
			"a=ice-ufrag:abcd",
			"a=ice-pwd:0123456789abcdef",
			"a=fingerprint:sha-256 AA:BB:CC:DD",
			"a=setup:active",
			"a=mid:0",
			"a=sctp-port:5000",
			"a=max-message-size:262144"
		];
		foreach($candidates as $candidate){
			$lines[] = "a=" . $candidate;
		}

		return implode("\r\n", $lines) . "\r\n";
	}
}

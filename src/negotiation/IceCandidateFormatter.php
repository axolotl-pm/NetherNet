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

namespace pocketmine\nethernet\negotiation;

use function count;
use function explode;
use function implode;
use function str_starts_with;
use function substr;

/**
 * Formats ICE candidate strings to match NetherNet and Minecraft WebRTC attribute expectations.
 */
final class IceCandidateFormatter{

	private const COMPONENT = "1";

	private function __construct(){}

	/**
	 * @param string $ufrag Local ICE username fragment.
	 * @param int    $index Candidate index used for the `network-id` attribute.
	 *
	 * @throws NegotiationException
	 */
	public static function format(string $candidate, string $ufrag, int $index) : string{
		if(str_starts_with($candidate, "a=")){
			$candidate = substr($candidate, 2);
		}
		if(!str_starts_with($candidate, "candidate:")){
			throw new NegotiationException("Candidate does not start with \"candidate:\": $candidate", ErrorCode::CANDIDATE_ADD);
		}

		$parts = explode(" ", substr($candidate, 10));
		if(count($parts) < 8 || $parts[6] !== "typ"){
			throw new NegotiationException("Candidate is not in the expected form: $candidate", ErrorCode::CANDIDATE_ADD);
		}

		[$foundation, , $protocol, $priority, $address, $port, , $type] = $parts;

		$formatted = [
			"candidate:" . $foundation,
			self::COMPONENT,
			$protocol,
			$priority,
			$address,
			$port,
			"typ",
			$type
		];

		// Zero reflexive/relayed base addresses for privacy while satisfying expected format
		if($type === "relay" || $type === "srflx"){
			$formatted[] = "raddr";
			$formatted[] = "0.0.0.0";
			$formatted[] = "rport";
			$formatted[] = "0";
		}

		$formatted[] = "generation";
		$formatted[] = "0";
		$formatted[] = "ufrag";
		$formatted[] = $ufrag;
		$formatted[] = "network-id";
		$formatted[] = (string) $index;
		$formatted[] = "network-cost";
		$formatted[] = "0";

		return implode(" ", $formatted);
	}
}

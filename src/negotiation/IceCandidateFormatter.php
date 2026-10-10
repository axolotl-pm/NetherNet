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

use pocketmine\nethernet\protocol\ErrorCode;
use pocketmine\nethernet\sdp\SessionDescription;
use pocketmine\nethernet\utils\InternetAddress;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function in_array;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * @internal
 * Formats ICE candidate strings to match NetherNet and Minecraft WebRTC attribute expectations.
 */
final class IceCandidateFormatter{

	private const PREFIX = "candidate:";

	private function __construct(){}

	/**
	 * Builds a server-reflexive candidate
	 *
	 * @param string $address IP address in canonical form
	 *
	 * @return string|null null when the offer already carries the address, or has no UDP host candidate.
	 */
	public static function signalingPeer(SessionDescription $offer, string $address) : ?string{
		$port = null;
		foreach(array_merge($offer->getSessionAttributeValues("candidate"), $offer->getMediaAttributeValues("candidate")) as $value){
			$candidate = IceCandidate::parse($value);
			if($candidate === null){
				continue;
			}
			if((InternetAddress::normalizeIp($candidate->address) ?? $candidate->address) === $address){
				return null;
			}
			if($port === null && $candidate->isUdpHost()){
				$port = $candidate->port;
			}
		}
		if($port === null){
			return null;
		}

		return self::PREFIX . IceCandidate::serverReflexive("signaling", $address, $port)->toString();
	}

	/**
	 * Rewrites gathered candidates so that clients are only offered the specified addresses. Candidates on any other
	 * address are dropped, and addresses not matching any gathered candidate are announced as server-reflexive on
	 * the port of the first UDP host candidate, simulating a 1:1 port-forwarded NAT.
	 *
	 * @param string[] $candidates candidate attribute values, without the "a=candidate:" prefix
	 * @phpstan-param list<string> $candidates
	 * @param string[] $addresses
	 * @phpstan-param list<string> $addresses
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function advertise(array $candidates, array $addresses) : array{
		$addresses = array_values(array_unique(array_map(fn(string $address) => InternetAddress::normalizeIp($address) ?? $address, $addresses)));
		$kept = [];
		$matchedAddresses = [];
		$port = null;
		foreach($candidates as $value){
			$candidate = IceCandidate::parse($value);
			if($candidate === null){
				continue;
			}
			if($port === null && $candidate->isUdpHost()){
				$port = $candidate->port;
			}
			$address = InternetAddress::normalizeIp($candidate->address) ?? $candidate->address;
			if(in_array($address, $addresses, true)){
				$kept[] = $value;
				$matchedAddresses[$address] = true;
			}
		}
		if($port === null){
			return $kept;
		}

		foreach($addresses as $address){
			if(!isset($matchedAddresses[$address])){
				$kept[] = IceCandidate::serverReflexive("advertised", $address, $port)->toString();
			}
		}

		return $kept;
	}

	/**
	 * Returns a gathered candidate in the form it is signaled to a client in.
	 *
	 * @throws NegotiationException
	 */
	public static function format(string $candidate, string $ufrag, int $index) : string{
		if(str_starts_with($candidate, "a=")){
			$candidate = substr($candidate, 2);
		}
		if(!str_starts_with($candidate, self::PREFIX)){
			throw new NegotiationException("Candidate does not start with \"" . self::PREFIX . "\": $candidate", ErrorCode::CANDIDATE_ADD);
		}

		$parsed = IceCandidate::parse(substr($candidate, strlen(self::PREFIX)));
		if($parsed === null){
			throw new NegotiationException("Candidate is not in the expected form: $candidate", ErrorCode::CANDIDATE_ADD);
		}

		return self::PREFIX . $parsed->toString() . " generation 0 ufrag $ufrag network-id $index network-cost 0";
	}
}

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

use pocketmine\nethernet\crypto\CryptoException;
use pocketmine\nethernet\identity\IdentityProvider;
use pocketmine\nethernet\sdp\SdpException;
use pocketmine\nethernet\sdp\SessionDescription;
use pocketmine\nethernet\utils\InternetAddress;

/**
 * @internal
 */
final class AnswerBuilder{

	/**
	 * @param string[]|null $advertisedAddresses
	 * @phpstan-param list<string>|null $advertisedAddresses
	 */
	public function __construct(
		private readonly IdentityProvider $identityProvider,
		private readonly ?array $advertisedAddresses = null
	){
		foreach($advertisedAddresses ?? [] as $address){
			if(InternetAddress::normalizeIp($address) === null){
				throw new \InvalidArgumentException("Advertised address \"$address\" is not an IP address");
			}
		}
	}

	/**
	 * @throws SdpException
	 * @throws CryptoException
	 */
	public function build(string $localDescription, CandidateMode $candidateMode) : LocalAnswer{
		$answer = SessionDescription::parse($localDescription);
		$ufrag = $answer->getMediaAttributeValues("ice-ufrag")[0] ?? null;
		if($ufrag === null){
			throw new SdpException("Local description has no ice-ufrag");
		}

		// Advertised addresses only rewrite bundled answers; trickle ICE is used for LAN discovery where local gathered addresses are required.
		if($candidateMode === CandidateMode::TRICKLE){
			$answer = $answer->withoutCandidates();
		}elseif($this->advertisedAddresses !== null){
			$answer = $answer->withCandidates(IceCandidateFormatter::advertise($answer->getMediaAttributeValues("candidate"), $this->advertisedAddresses));
		}

		$assertion = $this->identityProvider->issue($answer->getFingerprint());

		return new LocalAnswer($answer->withIdentity($assertion->encode())->toString(), $ufrag);
	}
}

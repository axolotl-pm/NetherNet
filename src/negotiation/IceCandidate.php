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
use function filter_var;
use function implode;
use function strtolower;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

/**
 * @internal
 * The fields of an SDP candidate attribute that NetherNet signals.
 */
final class IceCandidate{

	private const TYPE_HOST = "host";
	private const TYPE_SERVER_REFLEXIVE = "srflx";
	private const TYPE_RELAY = "relay";

	private const COMPONENT = "1";

	/** RFC 8445 section 5.1.2.1 priority for a server-reflexive candidate */
	private const SERVER_REFLEXIVE_PRIORITY = (100 << 24) + (65535 << 8) + 255;

	public function __construct(
		public readonly string $foundation,
		public readonly string $protocol,
		public readonly string $priority,
		public readonly string $address,
		public readonly string $port,
		public readonly string $type
	){}

	/**
	 * @param string $value Candidate attribute value, without the "candidate:" prefix.
	 */
	public static function parse(string $value) : ?self{
		$parts = explode(" ", $value);
		if(count($parts) < 8 || $parts[6] !== "typ"){
			return null;
		}

		return new self($parts[0], $parts[2], $parts[3], $parts[4], $parts[5], $parts[7]);
	}

	public static function serverReflexive(string $foundation, string $address, string $port) : self{
		return new self($foundation, "udp", (string) self::SERVER_REFLEXIVE_PRIORITY, $address, $port, self::TYPE_SERVER_REFLEXIVE);
	}

	public function isUdpHost() : bool{
		return $this->type === self::TYPE_HOST && strtolower($this->protocol) === "udp";
	}

	/**
	 * Returns the attribute value, without the "candidate:" prefix. The address a reflexive or relayed candidate was
	 * derived from is always written as unspecified, which keeps it private while leaving the form clients expect.
	 */
	public function toString() : string{
		$parts = [$this->foundation, self::COMPONENT, $this->protocol, $this->priority, $this->address, $this->port, "typ", $this->type];
		if($this->type === self::TYPE_SERVER_REFLEXIVE || $this->type === self::TYPE_RELAY){
			$ipv6 = filter_var($this->address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
			$parts[] = "raddr";
			$parts[] = $ipv6 ? "::" : "0.0.0.0";
			$parts[] = "rport";
			$parts[] = "0";
		}

		return implode(" ", $parts);
	}
}

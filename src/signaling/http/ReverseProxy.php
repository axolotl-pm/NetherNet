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

namespace pocketmine\nethernet\signaling\http;

use function array_reverse;
use function count;
use function explode;
use function filter_var;
use function inet_pton;
use function is_numeric;
use function ord;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strpos;
use function substr;
use function trim;
use const FILTER_VALIDATE_IP;

final class ReverseProxy{

	public const HEADER_FORWARDED_FOR = "X-Forwarded-For";
	public const HEADER_REAL_IP = "X-Real-IP";
	public const HEADER_CF_CONNECTING_IP = "CF-Connecting-IP";

	/**
	 * @var array<int, array{string, int}>
	 * @phpstan-var list<array{string, int}>
	 */
	private array $networks = [];

	/**
	 * @phpstan-param list<string> $headers
	 * @phpstan-param list<string> $networks
	 *
	 * @throws \InvalidArgumentException if a network cannot be parsed
	 */
	public function __construct(
		private readonly array $headers,
		array $networks = []
	){
		if(count($headers) === 0){
			throw new \InvalidArgumentException("At least one header is needed to find the client address");
		}
		foreach($networks as $network){
			$this->networks[] = self::parseNetwork($network);
		}
	}

	/**
	 * @phpstan-param list<string> $networks
	 */
	public static function nginx(array $networks = []) : self{
		return new self([self::HEADER_REAL_IP, self::HEADER_FORWARDED_FOR], $networks);
	}

	/**
	 * @phpstan-param list<string> $networks
	 */
	public static function caddy(array $networks = []) : self{
		return new self([self::HEADER_FORWARDED_FOR], $networks);
	}

	/**
	 * @phpstan-param list<string> $networks
	 */
	public static function cloudflare(array $networks = []) : self{
		return new self([self::HEADER_CF_CONNECTING_IP, self::HEADER_FORWARDED_FOR], $networks);
	}

	/**
	 * Returns whether the given peer address is a trusted proxy.
	 */
	public function trusts(string $address) : bool{
		if(count($this->networks) === 0){
			return true;
		}

		$packed = @inet_pton($address);
		if($packed === false){
			return false;
		}
		foreach($this->networks as [$network, $prefix]){
			if(self::inNetwork($packed, $network, $prefix)){
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the client IP address from the request headers, or null if no valid address is found.
	 *
	 * For X-Forwarded-For, addresses are evaluated from right to left, skipping trusted proxies to find
	 * the originating client address.
	 */
	public function clientAddress(HttpRequest $request) : ?string{
		foreach($this->headers as $header){
			$value = $request->getHeader($header);
			if($value === null){
				continue;
			}

			if(strcasecmp($header, self::HEADER_FORWARDED_FOR) === 0){
				foreach(array_reverse(explode(",", $value)) as $entry){
					$address = self::normalize($entry);
					if($address === null){
						continue;
					}
					if(count($this->networks) === 0 || !$this->trusts($address)){
						return $address;
					}
				}
				continue;
			}

			$address = self::normalize($value);
			if($address !== null){
				return $address;
			}
		}

		return null;
	}

	/**
	 * Normalizes an IP address by stripping IPv6 brackets and port numbers. Returns null if invalid.
	 */
	private static function normalize(string $entry) : ?string{
		$entry = trim($entry);
		if(str_starts_with($entry, "[")){
			$close = strpos($entry, "]");
			if($close === false){
				return null;
			}
			$entry = substr($entry, 1, $close - 1);
		}elseif(preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $entry, $matches) === 1){
			$entry = $matches[1];
		}

		return filter_var($entry, FILTER_VALIDATE_IP) !== false ? $entry : null;
	}

	/**
	 * @phpstan-return array{string, int}
	 *
	 * @throws \InvalidArgumentException
	 */
	private static function parseNetwork(string $network) : array{
		$address = $network;
		$prefix = null;
		if(str_contains($network, "/")){
			[$address, $prefixString] = explode("/", $network, 2);
			if(!is_numeric($prefixString)){
				throw new \InvalidArgumentException("Network \"$network\" has a prefix length that is not a number");
			}
			$prefix = (int) $prefixString;
		}

		$packed = @inet_pton($address);
		if($packed === false){
			throw new \InvalidArgumentException("Network \"$network\" is not an IP address or CIDR block");
		}

		$bits = strlen($packed) * 8;
		$prefix ??= $bits;
		if($prefix < 0 || $prefix > $bits){
			throw new \InvalidArgumentException("Network \"$network\" has a prefix length outside 0-$bits");
		}

		return [$packed, $prefix];
	}

	private static function inNetwork(string $packed, string $network, int $prefix) : bool{
		if(strlen($packed) !== strlen($network)){
			return false;
		}

		$wholeBytes = $prefix >> 3;
		if(substr($packed, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)){
			return false;
		}

		$remainingBits = $prefix & 7;
		if($remainingBits === 0){
			return true;
		}
		$mask = (0xff << (8 - $remainingBits)) & 0xff;

		return (ord($packed[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
	}
}

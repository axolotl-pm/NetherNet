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

use function microtime;
use const INF;

final class AddressBlockTracker{

	/** @phpstan-var array<string, float> */
	private array $blocked = [];

	private float $nextExpiry = INF;

	public function block(string $address, float $timeout) : void{
		$until = $timeout < 0 ? INF : microtime(true) + $timeout;
		if(($this->blocked[$address] ?? 0.0) >= $until){
			return;
		}

		$this->blocked[$address] = $until;
		if($until < $this->nextExpiry){
			$this->nextExpiry = $until;
		}
	}

	public function unblock(string $address) : void{
		unset($this->blocked[$address]);
	}

	public function isBlocked(string $address) : bool{
		$until = $this->blocked[$address] ?? null;

		return $until !== null && $until > microtime(true);
	}

	public function prune() : void{
		$now = microtime(true);
		if($now < $this->nextExpiry){
			return;
		}

		$this->nextExpiry = INF;
		foreach($this->blocked as $address => $until){
			if($until <= $now){
				unset($this->blocked[$address]);
			}elseif($until < $this->nextExpiry){
				$this->nextExpiry = $until;
			}
		}
	}
}

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

namespace pocketmine\nethernet\signaling\lan;

/**
 * Tracks which reliable sequences from one sender and nonce have already been delivered.
 */
final class ReliableReceiveWindow{

	public const SIZE = 256;

	private int $highestSequence = 0;
	/** @phpstan-var array<int, true> */
	private array $received = [];

	public function __construct(
		private float $lastActivity
	){}

	public function getLastActivity() : float{ return $this->lastActivity; }

	public function covers(int $sequence) : bool{
		return $sequence > $this->highestSequence - self::SIZE;
	}

	/**
	 * Returns false if the sequence was already received.
	 */
	public function receive(int $sequence, float $now) : bool{
		$this->lastActivity = $now;

		if($sequence > $this->highestSequence){
			$this->highestSequence = $sequence;
			foreach($this->received as $received => $_){
				if(!$this->covers($received)){
					unset($this->received[$received]);
				}
			}
		}
		if(isset($this->received[$sequence])){
			return false;
		}
		$this->received[$sequence] = true;

		return true;
	}
}

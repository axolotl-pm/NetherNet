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

use pocketmine\nethernet\signaling\Signaling;
use pocketmine\nethernet\signaling\SignalingContext;
use pocketmine\nethernet\signaling\SignalingException;

final class FakeSignaling implements Signaling{

	public ?SignalingContext $context = null;
	/** @phpstan-var list<float> */
	public array $ticks = [];
	public int $shutdowns = 0;

	public function __construct(
		private readonly bool $failToStart = false
	){}

	public function start(SignalingContext $context) : void{
		if($this->failToStart){
			throw new SignalingException("start refused by the test");
		}
		$this->context = $context;
	}

	public function tick(float $now) : void{
		$this->ticks[] = $now;
	}

	public function shutdown() : void{
		++$this->shutdowns;
	}
}

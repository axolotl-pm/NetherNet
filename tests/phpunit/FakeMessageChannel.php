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

use pocketmine\nethernet\protocol\Reliability;
use pocketmine\nethernet\transport\MessageChannel;
use pocketmine\nethernet\transport\TransportException;
use function array_shift;
use function count;
use function strlen;

final class FakeMessageChannel implements MessageChannel{

	/** @phpstan-var list<string> */
	public array $sent = [];
	/** @phpstan-var list<string> */
	public array $incoming = [];

	public bool $open = true;
	public bool $closed = false;
	public int $buffered = 0;
	public int $maxMessageSize = 262144;
	public bool $failSends = false;
	public bool $failReceives = false;

	public function __construct(
		public ?Reliability $reliability,
		private readonly ?string $label = null
	){}

	public function getLabel() : string{
		return $this->label ?? $this->reliability?->getChannelLabel() ?? "UnknownChannel";
	}

	public function getReliability() : ?Reliability{ return $this->reliability; }

	public function isOpen() : bool{ return $this->open && !$this->closed; }

	public function isClosed() : bool{ return $this->closed; }

	public function getMaxMessageSize() : int{ return $this->maxMessageSize; }

	public function send(string $message) : void{
		if($this->failSends || $this->closed){
			throw new TransportException("send refused by the test");
		}
		$this->sent[] = $message;
	}

	public function receive() : ?string{
		if($this->failReceives){
			throw new TransportException("receive queue overflowed in the test");
		}

		return array_shift($this->incoming);
	}

	public function getBufferedAmount() : int{ return $this->buffered; }

	public function getAvailableAmount() : int{
		$bytes = 0;
		foreach($this->incoming as $message){
			$bytes += strlen($message);
		}

		return $bytes;
	}

	public function getQueuedMessageCount() : int{ return count($this->incoming); }

	public function close() : void{
		$this->closed = true;
	}
}

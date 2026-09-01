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

/**
 * In-memory mutable status provider for HTTP signaling probe responses.
 */
final class MutableServerStatusProvider implements ServerStatusProvider{

	public function __construct(
		private ?ServerStatus $status = null
	){}

	public function getServerStatus() : ?ServerStatus{ return $this->status; }

	public function setServerStatus(?ServerStatus $status) : void{
		$this->status = $status;
	}

	/**
	 * Updates server status parsed from a standard RakNet pong string.
	 */
	public function setPongData(string $pong) : void{
		$status = ServerStatus::fromPongData($pong);
		if($status !== null){
			$this->status = $status;
		}
	}
}

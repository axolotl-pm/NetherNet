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

use pocketmine\nethernet\transport\ConnectionBudgetConfiguration;
use pocketmine\nethernet\transport\PeerTransport;
use pocketmine\nethernet\transport\PeerTransportFactory;
use pocketmine\nethernet\transport\TransportException;

final class FakeTransportFactory implements PeerTransportFactory{

	/** @phpstan-var list<FakePeerTransport> */
	public array $created = [];

	public bool $fail = false;
	public bool $rejectOffers = false;

	public function create(ConnectionBudgetConfiguration $budget) : PeerTransport{
		if($this->fail){
			throw new TransportException("transport refused by the test");
		}

		$transport = new FakePeerTransport();
		$transport->rejectOffer = $this->rejectOffers;

		return $this->created[] = $transport;
	}
}

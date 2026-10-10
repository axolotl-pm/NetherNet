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

namespace pocketmine\nethernet\utils;

use PHPUnit\Framework\TestCase;
use function fclose;
use function socket_close;
use function stream_socket_client;
use function usleep;

final class SocketTest extends TestCase{

	private static function loopback() : InternetAddress{
		return new InternetAddress("127.0.0.1", 0, 4);
	}

	public function testDatagramsArriveWithWhereTheyCameFrom() : void{
		$a = new DatagramSocket(self::loopback());
		$b = new DatagramSocket(self::loopback());

		self::assertNull($b->receive(), "an idle socket reported a datagram");

		$a->send("hello", $b->getBindAddress());
		$received = null;
		for($attempt = 0; $attempt < 100 && $received === null; ++$attempt){
			$received = $b->receive();
			if($received === null){
				usleep(1000);
			}
		}

		self::assertNotNull($received);
		self::assertSame("hello", $received[0]);
		self::assertSame($a->getBindAddress()->port, $received[1]->port);

		$a->close();
		$b->close();
		$b->close();
	}

	/**
	 * The error carries the errno so that callers can tell an address in use from anything else without
	 * reading the message.
	 */
	public function testBindingAnAddressInUseIsReportedWithItsErrno() : void{
		$first = new TcpServerSocket(self::loopback());

		try{
			new TcpServerSocket($first->getBindAddress());
			self::fail("two listeners were bound to one address");
		}catch(SocketException $e){
			self::assertGreaterThan(0, $e->getCode());
		}finally{
			$first->close();
		}
	}

	public function testAcceptReturnsWaitingConnectionsAndThenNothing() : void{
		$server = new TcpServerSocket(self::loopback());
		self::assertNull($server->accept(), "an idle listener reported a connection");

		$client = stream_socket_client("tcp://127.0.0.1:" . $server->getBindAddress()->port, $errorCode, $errorMessage, 5);
		self::assertNotFalse($client, "could not connect: $errorMessage");

		$accepted = null;
		for($attempt = 0; $attempt < 100 && $accepted === null; ++$attempt){
			$accepted = $server->accept();
			if($accepted === null){
				usleep(1000);
			}
		}

		self::assertNotNull($accepted);
		self::assertSame("127.0.0.1", $accepted[1]?->ip);
		self::assertNull($server->accept());

		socket_close($accepted[0]);
		fclose($client);
		$server->close();
	}
}

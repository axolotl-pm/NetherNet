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

use function is_string;

/**
 * Keeps everything logged so a test can assert on it.
 *
 * Useful mainly in the negative: a transport that is doing nothing should say
 * nothing, and a loop that mistakes idleness for failure is otherwise invisible
 * until it fills somebody's console.
 */
final class RecordingLogger extends \SimpleLogger{

	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public array $messages = [];

	/**
	 * The interface leaves both parameters untyped, so neither can be assumed to
	 * be a string.
	 */
	public function log($level, $message){
		$this->messages[] = (is_string($level) ? $level : "?") . ": " . (is_string($message) ? $message : "<not a string>");
	}
}

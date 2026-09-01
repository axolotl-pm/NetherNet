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

namespace pocketmine\nethernet\sdp;

use function array_slice;
use function array_values;
use function count;
use function ctype_digit;
use function explode;
use function implode;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Line-based parser and manipulator for SDP descriptions.
 */
final class SessionDescription{

	public const ATTRIBUTE_IDENTITY = "identity";
	public const ATTRIBUTE_FINGERPRINT = "fingerprint";
	public const ATTRIBUTE_MAX_MESSAGE_SIZE = "max-message-size";

	private const MAX_MESSAGE_SIZE_LIMIT = 4294967295;

	private const REQUIRED_MEDIA_ATTRIBUTES = ["ice-ufrag", "ice-pwd", "setup", self::ATTRIBUTE_MAX_MESSAGE_SIZE];

	public const MAX_LINES = 1024;

	/**
	 * @param string[] $lines
	 * @phpstan-param list<string> $lines
	 * @param int      $mediaStart Index of the first `m=` line, or line count if none.
	 */
	private function __construct(
		private readonly array $lines,
		private readonly int $mediaStart
	){}

	/**
	 * Parses an SDP string into individual lines (normalizing CRLF and LF).
	 *
	 * @throws SdpException
	 */
	public static function parse(string $sdp) : self{
		$lines = [];
		foreach(explode("\n", str_replace(["\r\n", "\r"], "\n", $sdp)) as $line){
			if($line === ""){
				continue;
			}
			if(count($lines) >= self::MAX_LINES){
				throw new SdpException("Session description has more than " . self::MAX_LINES . " lines");
			}
			$lines[] = $line;
		}
		if(count($lines) === 0){
			throw new SdpException("Session description is empty");
		}

		return new self($lines, self::findMediaStart($lines));
	}

	/**
	 * @param string[] $lines
	 * @phpstan-param list<string> $lines
	 */
	private static function findMediaStart(array $lines) : int{
		foreach($lines as $index => $line){
			if(str_starts_with($line, "m=")){
				return $index;
			}
		}

		return count($lines);
	}

	public function toString() : string{
		return implode("\r\n", $this->lines) . "\r\n";
	}

	public function __toString() : string{
		return $this->toString();
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getLines() : array{ return $this->lines; }

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getSessionAttributeValues(string $name) : array{
		return self::attributeValuesIn(array_slice($this->lines, 0, $this->mediaStart), $name);
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getMediaAttributeValues(string $name) : array{
		return self::attributeValuesIn(array_slice($this->lines, $this->mediaStart), $name);
	}

	/**
	 * @param string[] $lines
	 * @phpstan-param list<string> $lines
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private static function attributeValuesIn(array $lines, string $name) : array{
		$prefix = "a=" . $name . ":";
		$values = [];
		foreach($lines as $line){
			if(str_starts_with($line, $prefix)){
				$values[] = substr($line, strlen($prefix));
			}
		}

		return $values;
	}

	/**
	 * Extracts the DTLS certificate fingerprint (media-level preferred over session-level).
	 *
	 * @throws SdpException
	 */
	public function getFingerprint() : Fingerprint{
		$values = $this->getMediaAttributeValues(self::ATTRIBUTE_FINGERPRINT);
		if(count($values) === 0){
			$values = $this->getSessionAttributeValues(self::ATTRIBUTE_FINGERPRINT);
		}
		if(count($values) === 0){
			throw new SdpException("Session description has no fingerprint attribute");
		}
		if(count($values) !== 1){
			throw new SdpException("Expected exactly one fingerprint attribute, got " . count($values));
		}

		return Fingerprint::parse($values[0]);
	}

	/**
	 * Extracts and validates the maximum SCTP message size attribute.
	 *
	 * @throws SdpException
	 */
	public function getMaxMessageSize() : int{
		$values = $this->getMediaAttributeValues(self::ATTRIBUTE_MAX_MESSAGE_SIZE);
		if(count($values) === 0){
			throw new SdpException("Media section is missing the " . self::ATTRIBUTE_MAX_MESSAGE_SIZE . " attribute");
		}
		if(count($values) !== 1){
			throw new SdpException("Expected exactly one " . self::ATTRIBUTE_MAX_MESSAGE_SIZE . " attribute, got " . count($values));
		}

		$value = $values[0];
		if(!ctype_digit($value)){
			throw new SdpException("Attribute " . self::ATTRIBUTE_MAX_MESSAGE_SIZE . " is not a number: $value");
		}

		$size = (int) $value;
		if($size > self::MAX_MESSAGE_SIZE_LIMIT){
			throw new SdpException("Attribute " . self::ATTRIBUTE_MAX_MESSAGE_SIZE . " is wider than 32 bits: $value");
		}
		if($size <= 1){
			throw new SdpException("Attribute " . self::ATTRIBUTE_MAX_MESSAGE_SIZE . " must exceed one byte, got $size");
		}

		return $size;
	}

	/** Returns the raw value of the session-level `a=identity` attribute, if present. */
	public function getIdentity() : ?string{
		$values = $this->getSessionAttributeValues(self::ATTRIBUTE_IDENTITY);

		return $values[0] ?? null;
	}

	/**
	 * Returns a copy with all `a=identity` attributes removed.
	 */
	public function withoutIdentity() : self{
		$prefix = "a=" . self::ATTRIBUTE_IDENTITY . ":";
		$lines = [];
		foreach($this->lines as $line){
			if(!str_starts_with($line, $prefix)){
				$lines[] = $line;
			}
		}

		return new self($lines, self::findMediaStart($lines));
	}

	/**
	 * Returns a copy with all ICE candidate lines and end-of-candidates stripped.
	 */
	public function withoutCandidates() : self{
		$lines = [];
		foreach($this->lines as $line){
			if(!str_starts_with($line, "a=candidate:") && $line !== "a=end-of-candidates"){
				$lines[] = $line;
			}
		}

		return new self($lines, self::findMediaStart($lines));
	}

	/**
	 * Inserts or replaces the session-level `a=identity` attribute before the media section.
	 */
	public function withIdentity(string $identity) : self{
		$stripped = $this->withoutIdentity();
		$merged = array_values([
			...array_slice($stripped->lines, 0, $stripped->mediaStart),
			"a=" . self::ATTRIBUTE_IDENTITY . ":" . $identity,
			...array_slice($stripped->lines, $stripped->mediaStart)
		]);

		return new self($merged, self::findMediaStart($merged));
	}

	/**
	 * Validates required media sections and SDP attributes.
	 *
	 * @throws SdpException
	 */
	public function validate() : void{
		$mediaSections = 0;
		foreach($this->lines as $line){
			if(str_starts_with($line, "m=")){
				++$mediaSections;
			}
		}
		if($mediaSections !== 1){
			throw new SdpException("Expected exactly one media section, got $mediaSections");
		}

		foreach(self::REQUIRED_MEDIA_ATTRIBUTES as $name){
			if(count($this->getMediaAttributeValues($name)) === 0){
				throw new SdpException("Media section is missing the $name attribute");
			}
		}

		$this->getFingerprint();
		$this->getMaxMessageSize();
	}
}

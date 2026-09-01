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

use function count;
use function explode;
use function preg_match;
use function rtrim;
use function str_replace;
use function strcasecmp;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

/**
 * Strict parser for HTTP/1.1 request heads used in signaling endpoints.
 */
final class HttpRequest{

	public const HEADER_CONTENT_LENGTH = "content-length";
	public const HEADER_TRANSFER_ENCODING = "transfer-encoding";

	/**
	 * Headers where duplicates are strictly disallowed to prevent request smuggling.
	 */
	private const DECISIVE_HEADERS = [
		self::HEADER_CONTENT_LENGTH => true,
		self::HEADER_TRANSFER_ENCODING => true,
		"content-type" => true,
		"connection" => true,
		"host" => true
	];

	private const TOKEN_PATTERN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

	/**
	 * @param array<string, string> $headers Keyed by lowercase header name.
	 */
	private function __construct(
		public readonly string $method,
		public readonly string $target,
		public readonly string $version,
		public readonly array $headers
	){}

	/**
	 * @throws HttpException if the request head is malformed or violates RFC 9112
	 */
	public static function parse(string $head) : self{
		$lines = explode("\n", str_replace("\r\n", "\n", $head));

		$requestLine = $lines[0] ?? "";
		if(preg_match('#^([A-Z]+) (/[^\x00-\x20\x7f]*) (HTTP/1\.1)$#', $requestLine, $matches) !== 1){
			throw new HttpException(400, "Malformed request line");
		}

		$headers = [];
		for($i = 1, $count = count($lines); $i < $count; $i++){
			$line = $lines[$i];
			if($line === ""){
				continue;
			}

			// Reject obsolete line folding (RFC 9112)
			if($line[0] === " " || $line[0] === "\t"){
				throw new HttpException(400, "Obsolete line folding is not accepted");
			}

			$colon = strpos($line, ":");
			if($colon === false){
				throw new HttpException(400, "Malformed header line");
			}

			$name = substr($line, 0, $colon);
			if($name === ""){
				throw new HttpException(400, "Empty header name");
			}
			if(rtrim($name) !== $name){
				throw new HttpException(400, "Whitespace is not allowed before the colon in \"$name\"");
			}

			if(preg_match(self::TOKEN_PATTERN, $name) !== 1){
				throw new HttpException(400, "Header name is not a valid token");
			}
			$name = strtolower($name);

			if(isset($headers[$name]) && isset(self::DECISIVE_HEADERS[$name])){
				throw new HttpException(400, "Duplicate $name header");
			}

			$value = trim(substr($line, $colon + 1));
			if(preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1){
				throw new HttpException(400, "Header \"$name\" contains a control character");
			}

			$headers[$name] = $value;
		}

		if(isset($headers[self::HEADER_TRANSFER_ENCODING])){
			throw new HttpException(501, "Transfer-Encoding is not supported");
		}

		if(!isset($headers["host"])){
			throw new HttpException(400, "Host header is required");
		}

		return new self(strtoupper($matches[1]), $matches[2], $matches[3], $headers);
	}

	public function getHeader(string $name) : ?string{
		return $this->headers[strtolower($name)] ?? null;
	}

	/** Returns the target path with query strings or fragments stripped. */
	public function getPath() : string{
		$target = $this->target;
		foreach(["?", "#"] as $separator){
			$position = strpos($target, $separator);
			if($position !== false){
				$target = substr($target, 0, $position);
			}
		}

		return $target;
	}

	/**
	 * @throws HttpException if Content-Length is missing, invalid, or exceeds limit.
	 */
	public function getContentLength(int $limit) : int{
		$value = $this->getHeader(self::HEADER_CONTENT_LENGTH);
		if($value === null){
			throw new HttpException(411, "Content-Length is required");
		}
		if(preg_match('/^\d+$/', $value) !== 1){
			throw new HttpException(400, "Content-Length is not a number");
		}

		$length = (int) $value;
		if($length > $limit){
			throw new HttpException(413, "Body of $length bytes exceeds the $limit byte limit");
		}

		return $length;
	}

	public function hasContentType(string $expected) : bool{
		$value = $this->getHeader("content-type");
		if($value === null){
			return false;
		}

		$semicolon = strpos($value, ";");
		$mediaType = $semicolon === false ? $value : substr($value, 0, $semicolon);

		return strcasecmp(trim($mediaType), $expected) === 0;
	}
}

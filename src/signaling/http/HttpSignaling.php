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

use pocketmine\nethernet\crypto\OpenSsl;
use pocketmine\nethernet\negotiation\NegotiationException;
use pocketmine\nethernet\negotiation\Negotiator;
use pocketmine\nethernet\signaling\SignalingException;
use pocketmine\nethernet\signaling\SignalingInterface;
use function bin2hex;
use function count;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function implode;
use function is_file;
use function is_readable;
use function is_resource;
use function is_string;
use function microtime;
use function min;
use function preg_replace;
use function rawurldecode;
use function restore_error_handler;
use function set_error_handler;
use function socket_accept;
use function socket_bind;
use function socket_clear_error;
use function socket_close;
use function socket_create;
use function socket_export_stream;
use function socket_getpeername;
use function socket_last_error;
use function socket_listen;
use function socket_set_nonblock;
use function socket_set_option;
use function socket_strerror;
use function str_split;
use function str_starts_with;
use function stream_context_set_option;
use function stream_set_blocking;
use function stream_socket_enable_crypto;
use function stream_socket_recvfrom;
use function strlen;
use function strpos;
use function substr;
use const AF_INET;
use const SO_REUSEADDR;
use const SOCK_STREAM;
use const SOCKET_EWOULDBLOCK;
use const SOL_SOCKET;
use const SOL_TCP;
use const STREAM_CRYPTO_METHOD_TLS_SERVER;
use const STREAM_PEEK;

/**
 * Non-blocking HTTP/HTTPS Partner Signaling Server implementing Capability Checks and SDP Exchanges.
 *
 * Exposes:
 * - `GET /v1/join`: Capability probe returning HTTP 2xx and optional server status.
 * - `POST /v1/join/{networkId}`: SDP offer exchange returning the server's SDP answer.
 */
final class HttpSignaling implements SignalingInterface{

	public const PATH_JOIN = "/v1/join";
	public const CONTENT_TYPE_SDP = "application/sdp";
	public const CONTENT_TYPE_JSON = "application/json";
	public const CONTENT_TYPE_TEXT = "text/plain; charset=utf-8";

	public const MAX_BODY_SIZE = 65536;
	public const MAX_HEAD_SIZE = 16384;
	public const MAX_NETWORK_ID_LENGTH = 256;

	public const DEFAULT_MAX_CONNECTIONS = 256;

	public const HEAD_TIMEOUT = 5.0;
	public const BODY_TIMEOUT = 10.0;
	public const NEGOTIATION_TIMEOUT = 20.0;
	public const WRITE_TIMEOUT = 10.0;

	private const BACKLOG = 128;
	private const READ_CHUNK_SIZE = 65536;
	private const MAX_DRAIN_ON_CLOSE = 262144;

	private ?\Socket $socket = null;

	/**
	 * @var HttpConnection[]
	 * @phpstan-var array<int, HttpConnection>
	 */
	private array $connections = [];

	private int $nextConnectionId = 0;
	private bool $closed = false;

	/**
	 * @param array<string, mixed>|null $tlsContext SSL context options for HTTPS support (TLS trust anchor).
	 * @param ServerStatusProvider|null $statusProvider Provider for capability probe (`GET /v1/join`) responses.
	 */
	public function __construct(
		private readonly Negotiator $negotiator,
		private readonly string $bindAddress,
		private readonly int $port,
		private readonly ?array $tlsContext = null,
		private readonly ?\Logger $logger = null,
		private readonly int $maxConnections = self::DEFAULT_MAX_CONNECTIONS,
		private readonly ?ServerStatusProvider $statusProvider = null
	){
		if($maxConnections < 1){
			throw new \InvalidArgumentException("Maximum connections must be positive, got $maxConnections");
		}
	}

	public function start() : void{
		if($this->socket !== null){
			throw new SignalingException("Already listening");
		}

		$this->checkTlsFilesReadable();

		$socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if($socket === false){
			throw new SignalingException("Could not create a socket: " . socket_strerror(socket_last_error()));
		}

		@socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

		if(!@socket_bind($socket, $this->bindAddress, $this->port)){
			$error = socket_strerror(socket_last_error($socket));
			socket_close($socket);

			throw new SignalingException("Failed to listen on $this->bindAddress:$this->port: $error");
		}
		if(!@socket_listen($socket, self::BACKLOG)){
			$error = socket_strerror(socket_last_error($socket));
			socket_close($socket);

			throw new SignalingException("Failed to listen on $this->bindAddress:$this->port: $error");
		}

		socket_set_nonblock($socket);
		$this->socket = $socket;
	}

	/**
	 * Validates TLS certificate and private key file accessibility at startup.
	 *
	 * @throws SignalingException
	 */
	private function checkTlsFilesReadable() : void{
		if($this->tlsContext === null){
			return;
		}

		foreach(["local_cert", "local_pk"] as $option){
			$path = $this->tlsContext[$option] ?? null;
			if($path === null){
				continue;
			}
			if(!is_string($path)){
				throw new SignalingException("TLS option $option must be a path");
			}
			if(!is_file($path) || !is_readable($path)){
				throw new SignalingException("TLS option $option points at $path, which cannot be read");
			}
		}
	}

	/**
	 * Exports the accepted socket descriptor into a non-blocking stream for TLS and HTTP I/O.
	 */
	private function adopt(\Socket $connection, float $now) : ?HttpConnection{
		socket_set_nonblock($connection);

		$peerAddress = "";
		$peerPort = 0;
		$peerName = @socket_getpeername($connection, $peerAddress, $peerPort) ? "$peerAddress:$peerPort" : "unknown";

		$stream = socket_export_stream($connection);
		if($stream === false){
			$this->logger?->debug("Could not read the accepted socket as a stream; dropping $peerName");
			socket_close($connection);

			return null;
		}
		stream_set_blocking($stream, false);

		$connectionState = new HttpConnection(
			$stream,
			$connection,
			$peerName,
			$now + self::HEAD_TIMEOUT,
			$now + self::BODY_TIMEOUT
		);

		if($this->tlsContext !== null){
			foreach($this->tlsContext as $option => $value){
				stream_context_set_option($stream, "ssl", $option, $value);
			}
			$connectionState->state = HttpConnectionState::HANDSHAKE;
		}

		return $connectionState;
	}

	/**
	 * Accepts incoming connections and advances pending requests and responses.
	 */
	public function tick() : void{
		$socket = $this->socket;
		if($this->closed || $socket === null){
			return;
		}

		$now = microtime(true);

		socket_clear_error();

		while(($accepted = @socket_accept($socket)) !== false){
			if(count($this->connections) >= $this->maxConnections && !$this->evictStalest()){
				$this->logger?->debug("Refused a signalling connection; all slots are busy");
				socket_close($accepted);
				continue;
			}

			$connection = $this->adopt($accepted, $now);
			if($connection === null){
				continue;
			}

			$this->logger?->debug("Signalling connection opened from " . $connection->peerName);
			$this->connections[$this->nextConnectionId++] = $connection;
		}

		$errno = socket_last_error();
		if($errno !== 0 && $errno !== SOCKET_EWOULDBLOCK){
			$this->logger?->debug("Accept failed: " . socket_strerror($errno));
		}
		socket_clear_error();

		foreach($this->connections as $id => $connection){
			try{
				$this->advance($connection, $now);
			}catch(HttpException $e){
				$this->logger?->debug($connection->peerName . ": " . $e->getMessage());
				$this->respond($connection, $e->getStatusCode(), "");
			}catch(\Throwable $e){
				$this->logger?->debug($connection->peerName . ": unhandled " . $e::class . ": " . $e->getMessage());
				$this->respond($connection, 500, "");
			}

			if($connection->state === HttpConnectionState::DONE){
				$this->disconnect($connection);
				unset($this->connections[$id]);
			}
		}
	}

	public function shutdown() : void{
		if($this->closed){
			return;
		}
		$this->closed = true;

		foreach($this->connections as $connection){
			$this->disconnect($connection);
		}
		$this->connections = [];

		if($this->socket !== null){
			socket_close($this->socket);
			$this->socket = null;
		}
	}

	/**
	 * @throws HttpException
	 */
	private function advance(HttpConnection $connection, float $now) : void{
		switch($connection->state){
			case HttpConnectionState::HANDSHAKE:
				$opening = self::peekFirstByte($connection);
				if($opening === null){
					if($now > $connection->headDeadline){
						throw new HttpException(408, "Timed out waiting for a TLS handshake");
					}
					$this->checkPeerGone($connection);

					return;
				}
				// Handshake record begins with 0x16; redirect plain HTTP attempts
				if($opening !== "\x16"){
					$connection->redirectToTls = true;
					$connection->state = HttpConnectionState::READING_HEAD;

					return;
				}

				$result = self::enableCrypto($connection->stream, $warning);
				if($result === false){
					throw new HttpException(400, "TLS handshake failed: " . ($warning ?? OpenSsl::lastError()));
				}
				if($result !== true){
					if($now > $connection->headDeadline){
						throw new HttpException(408, "Timed out completing the TLS handshake");
					}
					$this->checkPeerGone($connection);

					return;
				}

				$connection->headDeadline = $now + self::HEAD_TIMEOUT;
				$connection->bodyDeadline = $now + self::BODY_TIMEOUT;
				$connection->state = HttpConnectionState::READING_HEAD;

			case HttpConnectionState::READING_HEAD:
				$this->readInto($connection, self::MAX_HEAD_SIZE + 1);
				self::checkNotTls($connection);
				if($now > $connection->headDeadline){
					throw new HttpException(408, "Timed out reading the request head after " . strlen($connection->input) . " bytes: " . self::describeBytes($connection->input));
				}

				$head = self::findHeadEnd($connection->input);
				if($head === null){
					if(strlen($connection->input) > self::MAX_HEAD_SIZE){
						throw new HttpException(431, "Request head is too large");
					}
					$this->checkPeerGone($connection);

					return;
				}

				[$end, $terminatorLength] = $head;
				if($end > self::MAX_HEAD_SIZE){
					throw new HttpException(431, "Request head is too large");
				}

				$connection->request = HttpRequest::parse(substr($connection->input, 0, $end));
				$connection->bodyOffset = $end + $terminatorLength;

				if($connection->redirectToTls){
					$this->redirectToTls($connection, $connection->request);

					return;
				}

				$this->checkRoutable($connection->request);

				$connection->contentLength = $connection->request->method === "POST"
					? $connection->request->getContentLength(self::MAX_BODY_SIZE)
					: 0;
				$connection->state = HttpConnectionState::READING_BODY;

			case HttpConnectionState::READING_BODY:
				$this->readInto($connection, $connection->bodyOffset + $connection->contentLength);
				if($now > $connection->bodyDeadline){
					throw new HttpException(408, "Timed out reading the request body");
				}

				if(strlen($connection->input) - $connection->bodyOffset < $connection->contentLength){
					$this->checkPeerGone($connection);

					return;
				}

				$this->dispatch($connection, substr($connection->input, $connection->bodyOffset, $connection->contentLength));
				$connection->negotiationDeadline = $now + self::NEGOTIATION_TIMEOUT;
				return;

			case HttpConnectionState::NEGOTIATING:
				$negotiation = $connection->negotiation;
				if($negotiation === null){
					throw new HttpException(500, "No negotiation is attached to this request");
				}

				$answer = $negotiation->getAnswer();
				if($answer !== null){
					$this->respond($connection, 200, $answer, self::CONTENT_TYPE_SDP);
					return;
				}
				if($negotiation->isFailed()){
					$this->logger?->debug($connection->peerName . ": negotiation failed: " . ($negotiation->getFailureReason() ?? "no reason given"));
					$this->respond($connection, 500, (string) $negotiation->getFailureCode()->value, self::CONTENT_TYPE_TEXT);

					return;
				}
				if($now > $connection->negotiationDeadline){
					throw new HttpException(502, "Timed out producing an answer");
				}
				return;

			case HttpConnectionState::WRITING:
				$this->flush($connection);
				if($connection->state === HttpConnectionState::WRITING && $now > $connection->writeDeadline){
					$this->logger?->debug($connection->peerName . ": timed out sending the response");
					$connection->state = HttpConnectionState::DONE;
				}
				return;

			case HttpConnectionState::DONE:
				return;
		}
	}

	/**
	 * Validates HTTP route, method, headers, and NetworkID from the request URI path.
	 *
	 * @throws HttpException
	 */
	private function checkRoutable(HttpRequest $request) : void{
		$path = $request->getPath();

		if($path === self::PATH_JOIN || $path === self::PATH_JOIN . "/"){
			if($request->method !== "GET" && $request->method !== "HEAD"){
				throw new HttpException(405, "Only GET probes this endpoint");
			}

			return;
		}

		if(!str_starts_with($path, self::PATH_JOIN . "/")){
			throw new HttpException(404, "No such endpoint");
		}
		if($request->method !== "POST"){
			throw new HttpException(405, "Offers are posted");
		}
		if(!$request->hasContentType(self::CONTENT_TYPE_SDP)){
			throw new HttpException(415, "Offers must be sent as " . self::CONTENT_TYPE_SDP);
		}

		$networkId = self::networkIdOf($path);
		if($networkId === ""){
			throw new HttpException(404, "No NetworkID in the request path");
		}
		if(strlen($networkId) > self::MAX_NETWORK_ID_LENGTH){
			throw new HttpException(414, "NetworkID is longer than " . self::MAX_NETWORK_ID_LENGTH . " bytes");
		}
	}

	private static function networkIdOf(string $path) : string{
		return rawurldecode(substr($path, strlen(self::PATH_JOIN) + 1));
	}

	/**
	 * @throws HttpException
	 */
	private function dispatch(HttpConnection $connection, string $body) : void{
		$request = $connection->request;
		if($request === null){
			throw new HttpException(500, "Dispatching a request that was never parsed");
		}

		$path = $request->getPath();
		if($path === self::PATH_JOIN || $path === self::PATH_JOIN . "/"){
			$this->respondToProbe($connection);

			return;
		}

		try{
			$connection->negotiation = $this->negotiator->beginNegotiation($body, self::networkIdOf($path));
		}catch(NegotiationException $e){
			throw new HttpException(400, $e->getMessage());
		}

		$connection->state = HttpConnectionState::NEGOTIATING;
	}

	/**
	 * Responds to client capability probe requests (`GET /v1/join`).
	 */
	private function respondToProbe(HttpConnection $connection) : void{
		$status = null;
		try{
			$status = $this->statusProvider?->getServerStatus()?->toJson();
		}catch(\Throwable $e){
			$this->logger?->debug("Could not build the server status: " . $e->getMessage());
		}

		if($status === null){
			$this->respond($connection, 200, "");

			return;
		}

		$this->respond($connection, 200, $status, self::CONTENT_TYPE_JSON);
	}

	/**
	 * @param string[] $extraHeaders
	 * @phpstan-param array<string, string> $extraHeaders
	 */
	private function respond(HttpConnection $connection, int $statusCode, string $body, ?string $contentType = null, array $extraHeaders = []) : void{
		if($connection->state === HttpConnectionState::WRITING || $connection->state === HttpConnectionState::DONE){
			return;
		}

		$this->logger?->debug(
			$connection->peerName . ": " .
			($connection->request === null ? "<unparsed request>" : $connection->request->method . " " . $connection->request->getPath()) .
			" -> $statusCode " . self::getReasonPhrase($statusCode)
		);

		$head = "HTTP/1.1 $statusCode " . self::getReasonPhrase($statusCode) . "\r\n";
		if($contentType !== null){
			$head .= "Content-Type: $contentType\r\n";
		}
		foreach($extraHeaders as $name => $value){
			$head .= "$name: $value\r\n";
		}
		$head .= "Content-Length: " . strlen($body) . "\r\n";
		$head .= "Connection: close\r\n\r\n";

		$connection->output = $head . ($connection->request?->method === "HEAD" ? "" : $body);
		$connection->state = HttpConnectionState::WRITING;
		$connection->writeDeadline = microtime(true) + self::WRITE_TIMEOUT;

		$this->flush($connection);
	}

	private function flush(HttpConnection $connection) : void{
		while($connection->output !== ""){
			$written = @fwrite($connection->stream, $connection->output);
			if($written === false || $written === 0){
				if(feof($connection->stream)){
					$connection->state = HttpConnectionState::DONE;
				}
				return;
			}

			$connection->output = substr($connection->output, $written);
		}

		$connection->state = HttpConnectionState::DONE;
	}

	/**
	 * Reads incoming bytes up to the specified buffer limit.
	 */
	private function readInto(HttpConnection $connection, int $limit) : void{
		while(($wanted = $limit - strlen($connection->input)) > 0){
			$chunk = @fread($connection->stream, min($wanted, self::READ_CHUNK_SIZE));
			if($chunk === false || $chunk === ""){
				return;
			}
			$connection->input .= $chunk;
		}
	}

	/**
	 * Finds the line terminator separating the HTTP request head from the body.
	 *
	 * @return array{int, int}|null Offset of header terminator and terminator length.
	 */
	private static function findHeadEnd(string $input) : ?array{
		$crlf = strpos($input, "\r\n\r\n");
		$lf = strpos($input, "\n\n");

		if($crlf !== false && ($lf === false || $crlf < $lf)){
			return [$crlf, 4];
		}
		if($lf !== false){
			return [$lf, 2];
		}

		return null;
	}

	/**
	 * Returns formatted byte sample for debugging malformed request heads.
	 */
	private static function describeBytes(string $data, int $limit = 96) : string{
		$sample = substr($data, 0, $limit);

		$hex = implode(" ", str_split(bin2hex(substr($sample, 0, 16)), 2));
		$text = preg_replace('/[^\x20-\x7e]/', ".", $sample) ?? "";

		return "hex[$hex] text[$text]" . (strlen($data) > $limit ? "..." : "");
	}

	/**
	 * Sends a 308 Permanent Redirect to HTTPS preserving request method and body.
	 */
	private function redirectToTls(HttpConnection $connection, HttpRequest $request) : void{
		$host = $request->getHeader("host") ?? "";

		$this->respond($connection, 308, "", null, ["Location" => "https://" . $host . $request->target]);
	}

	/**
	 * Peeks the first byte from the incoming stream without consuming it.
	 */
	private static function peekFirstByte(HttpConnection $connection) : ?string{
		$peeked = @stream_socket_recvfrom($connection->stream, 1, STREAM_PEEK);

		return $peeked === false || $peeked === "" ? null : $peeked[0];
	}

	/**
	 * Detects TLS ClientHello records arriving on a plaintext listener.
	 *
	 * @throws HttpException
	 */
	private static function checkNotTls(HttpConnection $connection) : void{
		if(strlen($connection->input) >= 3 && $connection->input[0] === "\x16" && $connection->input[1] === "\x03"){
			throw new HttpException(400, "Peer is speaking TLS to a plaintext signalling endpoint; configure a certificate");
		}
	}

	/**
	 * Enables non-blocking TLS crypto on the stream, capturing error warnings.
	 *
	 * @param string|null $warning Reference parameter capturing PHP error message.
	 * @param resource    $stream
	 */
	private static function enableCrypto(mixed $stream, ?string &$warning) : bool|int{
		$warning = null;
		set_error_handler(function(int $severity, string $message) use (&$warning) : bool{
			$warning ??= $message;

			return true;
		});

		try{
			return stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
		}finally{
			restore_error_handler();
		}
	}

	private function checkPeerGone(HttpConnection $connection) : bool{
		if(!feof($connection->stream)){
			return false;
		}

		if($connection->input !== ""){
			$this->logger?->debug($connection->peerName . ": closed the connection after " . strlen($connection->input) . " bytes of a request");
		}
		$connection->state = HttpConnectionState::DONE;

		return true;
	}

	/**
	 * Evicts the least advanced pending connection when at capacity.
	 *
	 * @return bool false if all connections are already in progress.
	 */
	private function evictStalest() : bool{
		$stalestId = null;
		$stalestDeadline = 0.0;

		foreach($this->connections as $id => $connection){
			if(!$connection->state->isAwaitingRequest()){
				continue;
			}
			$deadline = match($connection->state){
				HttpConnectionState::READING_BODY => $connection->bodyDeadline,
				default => $connection->headDeadline
			};

			if($stalestId === null || $deadline < $stalestDeadline){
				$stalestId = $id;
				$stalestDeadline = $deadline;
			}
		}

		if($stalestId === null){
			return false;
		}

		$this->logger?->debug("Dropping the stalest signalling connection (" . $this->connections[$stalestId]->peerName . ") to make room");
		$this->disconnect($this->connections[$stalestId]);
		unset($this->connections[$stalestId]);

		return true;
	}

	private function disconnect(HttpConnection $connection) : void{
		if($connection->negotiation !== null && ($connection->negotiation->getAnswer() === null || $connection->output !== "")){
			$connection->negotiation->fail("Signalling connection closed before the answer was delivered");
		}

		if(is_resource($connection->stream)){
			$drained = 0;
			while($drained < self::MAX_DRAIN_ON_CLOSE){
				$chunk = @fread($connection->stream, self::READ_CHUNK_SIZE);
				if($chunk === false || $chunk === ""){
					break;
				}
				$drained += strlen($chunk);
			}

			fclose($connection->stream);
		}
	}

	private static function getReasonPhrase(int $statusCode) : string{
		return match($statusCode){
			200 => "OK",
			308 => "Permanent Redirect",
			400 => "Bad Request",
			404 => "Not Found",
			405 => "Method Not Allowed",
			408 => "Request Timeout",
			411 => "Length Required",
			413 => "Content Too Large",
			414 => "URI Too Long",
			415 => "Unsupported Media Type",
			431 => "Request Header Fields Too Large",
			500 => "Internal Server Error",
			501 => "Not Implemented",
			502 => "Bad Gateway",
			503 => "Service Unavailable",
			504 => "Gateway Timeout",
			default => "Error"
		};
	}
}

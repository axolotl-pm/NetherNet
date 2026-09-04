<?php

/**
 * @generate-class-entries
 * @generate-legacy-arginfo 80200
 */

namespace pmmp\webrtc;

/**
 * A single data channel.
 */
final class DataChannel
{
    private function __construct() {}

    public function getLabel(): string {}

    public function getProtocol(): string {}

    /** SCTP stream id, or null before the channel has been negotiated. */
    public function getId(): ?int {}

    public function isOpen(): bool {}

    public function isClosed(): bool {}

    public function isUnordered(): bool {}

    public function getMaxRetransmits(): ?int {}

    public function getMaxPacketLifeTime(): ?int {}

    /** Largest payload accepted by a single send(), as negotiated with the peer. */
    public function getMaxMessageSize(): int {}

    /** Bytes queued for sending but not yet handed to the network. */
    public function getBufferedAmount(): int {}

    /** Bytes waiting to be read by receive(). */
    public function getAvailableAmount(): int {}

    /** Messages waiting to be read by receive(), which empty ones add no bytes to. */
    public function getQueuedMessageCount(): int {}

    /**
     * Queue a binary message. Returns false if the message was buffered rather
     * than sent immediately, which is not an error.
     *
     * @throws WebRtcException if the channel is closed, the payload exceeds
     *                         getMaxMessageSize(), or the send queue has
     *                         reached the limit set on the connection
     */
    public function send(string $data): bool {}

    /** Take the next message, or null if none has arrived. */
    public function receive(): ?string {}

    /** Look at the next message without consuming it. */
    public function peek(): ?string {}

    public function close(): void {}
}

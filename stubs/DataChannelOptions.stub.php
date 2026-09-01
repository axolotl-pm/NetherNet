<?php

/**
 * @generate-class-entries
 * @generate-legacy-arginfo 80200
 */

namespace pmmp\webrtc;

/**
 * Reliability and negotiation settings for a data channel.
 *
 * A channel is reliable unless either setMaxRetransmits() or
 * setMaxPacketLifeTime() is given; the two are mutually exclusive.
 */
final class DataChannelOptions
{
    private function __construct() {}

    public static function create(): DataChannelOptions {}

    /** Allow out-of-order delivery. */
    public function setUnordered(bool $unordered): DataChannelOptions {}

    /** Give up after this many retransmissions. Null restores reliable delivery. */
    public function setMaxRetransmits(?int $count): DataChannelOptions {}

    /** Give up after this many milliseconds. Null restores reliable delivery. */
    public function setMaxPacketLifeTime(?int $milliseconds): DataChannelOptions {}

    /** Sub-protocol advertised to the peer. */
    public function setProtocol(string $protocol): DataChannelOptions {}

    /**
     * Skip in-band negotiation. Both sides must then create the channel with
     * the same explicit id.
     */
    public function setNegotiated(bool $negotiated): DataChannelOptions {}

    /** Fixed SCTP stream id. Null lets the implementation choose. */
    public function setId(?int $id): DataChannelOptions {}

    public function isUnordered(): bool {}

    public function getMaxRetransmits(): ?int {}

    public function getMaxPacketLifeTime(): ?int {}

    public function getProtocol(): string {}

    public function isNegotiated(): bool {}

    public function getId(): ?int {}
}

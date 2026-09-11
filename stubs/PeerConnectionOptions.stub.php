<?php

/**
 * @generate-class-entries
 * @generate-legacy-arginfo 80100
 */

namespace pmmp\webrtc;

/**
 * Builder for PeerConnection configuration. Setters return the same instance.
 *
 * @not-serializable
 */
final class PeerConnectionOptions
{
    private function __construct() {}

    public static function create(): PeerConnectionOptions {}

    /**
     * Maximum size in bytes of a single SCTP message. Emitted as
     * a=max-message-size in the local description.
     *
     * This also sizes the buffer a peer can make the transport allocate, so it
     * is capped at 256 MiB.
     *
     * @throws \ValueError if the size is outside 1..268435456
     */
    public function setMaxMessageSize(int $bytes): PeerConnectionOptions {}

    /**
     * Bytes this connection may hold waiting to be received, counted across all
     * of its data channels together. 0 removes the limit.
     */
    public function setMaxReceiveQueueSize(int $bytes): PeerConnectionOptions {}

    public function getMaxReceiveQueueSize(): int {}

    /**
     * Messages this connection may hold waiting to be received, counted across
     * all of its data channels together. 0 removes the limit.
     */
    public function setMaxReceiveQueueMessages(int $count): PeerConnectionOptions {}

    public function getMaxReceiveQueueMessages(): int {}

    /**
     * Bytes a single data channel may hold waiting to go out before send()
     * throws. 0 removes the limit.
     *
     * The transport buffers whatever it cannot write yet and never refuses on
     * its own, so without this a peer that stops reading decides how much
     * memory this process uses.
     */
    public function setMaxSendQueueSize(int $bytes): PeerConnectionOptions {}

    public function getMaxSendQueueSize(): int {}

    /**
     * Bytes a remote description passed to setRemoteOffer() or setRemoteAnswer()
     * may hold before it is refused unparsed. 0 removes the limit.
     *
     * Parsing an SDP costs time that grows with the square of the candidate
     * lines it carries, on the thread that called it, so without this a peer
     * decides how long that call blocks. A real data channel description is a
     * few kilobytes.
     */
    public function setMaxRemoteDescriptionSize(int $bytes): PeerConnectionOptions {}

    public function getMaxRemoteDescriptionSize(): int {}

    /**
     * Data channels a peer may have open and uncollected before further ones
     * are refused. 0 removes the limit.
     *
     * A channel stays alive until pollDataChannels() hands it over, so this
     * bounds what a peer can make this process hold on to.
     */
    public function setMaxPendingDataChannels(int $count): PeerConnectionOptions {}

    public function getMaxPendingDataChannels(): int {}

    public function setIceServers(IceServer ...$servers): PeerConnectionOptions {}

    /** Local UDP port range to gather ICE candidates from. */
    public function setPortRange(int $begin, int $end): PeerConnectionOptions {}

    /**
     * Maximum transmission unit (MTU) in bytes.
     *
     * The MTU must be at least 620 bytes for SCTP negotiation to succeed, and
     * at most 4144 bytes to prevent incoming packets from being truncated.
     *
     * @throws \ValueError if the size is outside 620..4144
     */
    public function setMtu(int $bytes): PeerConnectionOptions {}

    /**
     * Restrict candidate gathering to a single local address.
     */
    public function setBindAddress(?string $address): PeerConnectionOptions {}

    /**
     * Use an existing certificate rather than generating one.
     *
     * Both files must be readable at this point, even though they are not
     * parsed until a PeerConnection is constructed from these options.
     *
     * @throws \ValueError if a path contains a null byte, or names a file that
     *                     cannot be read or that open_basedir puts out of reach
     */
    public function setCertificate(string $certPemFile, string $keyPemFile, ?string $keyPemPass = null): PeerConnectionOptions {}

    /** Whether to gather TCP ICE candidates. Disabled by default. */
    public function setIceTcpEnabled(bool $enable): PeerConnectionOptions {}

    /**
     * Share a single UDP socket across all connections instead of binding a
     * separate socket per connection. Disabled by default.
     *
     * When enabled, all connections share the same port, allowing multiple
     * connections through a single forwarded port.
     *
     * TURN servers and ICE TCP are not supported in this mode.
     */
    public function setIceUdpMuxEnabled(bool $enable): PeerConnectionOptions {}

    /**
     * Restrict which candidate types may be used. RELAY forces every path
     * through a TURN server, so it needs at least one configured.
     */
    public function setIceTransportPolicy(TransportPolicy $policy): PeerConnectionOptions {}

    public function getIceTransportPolicy(): TransportPolicy {}

    /** Returns 0 if no explicit limit was set. */
    public function getMaxMessageSize(): int {}

    /** @return IceServer[] */
    public function getIceServers(): array {}

    public function getPortRangeBegin(): int {}

    public function getPortRangeEnd(): int {}

    /** Returns 0 if no explicit MTU is set. */
    public function getMtu(): int {}

    public function getBindAddress(): ?string {}

    public function getCertificatePemFile(): ?string {}

    public function getKeyPemFile(): ?string {}

    public function getKeyPemPass(): ?string {}

    public function isIceTcpEnabled(): bool {}

    public function isIceUdpMuxEnabled(): bool {}
}

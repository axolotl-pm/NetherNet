<?php

/**
 * @generate-class-entries
 * @generate-legacy-arginfo 80200
 */

namespace pmmp\webrtc;

/**
 * Builder for PeerConnection configuration. Setters return the same instance.
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

    /** Returns 0 if no explicit limit was set. */
    public function getMaxMessageSize(): int {}

    /** @return IceServer[] */
    public function getIceServers(): array {}

    public function getPortRangeBegin(): int {}

    public function getPortRangeEnd(): int {}

    public function getBindAddress(): ?string {}

    public function getCertificatePemFile(): ?string {}

    public function getKeyPemFile(): ?string {}

    public function getKeyPemPass(): ?string {}

    public function isIceTcpEnabled(): bool {}
}

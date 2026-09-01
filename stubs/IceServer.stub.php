<?php

/**
 * @generate-class-entries
 * @generate-legacy-arginfo 80200
 */

namespace pmmp\webrtc;

/**
 * A single STUN or TURN server.
 *
 * Instances are immutable and must be created through stun() or turn().
 */
final class IceServer
{
    private function __construct() {}

    public static function stun(string $hostname, int $port = 3478): IceServer {}

    public static function turn(string $hostname, int $port, string $username, string $password, RelayType $relayType = RelayType::UDP): IceServer {}

    public function getHostname(): string {}

    public function getPort(): int {}

    /** Null unless this is a TURN server. */
    public function getUsername(): ?string {}

    /** Null unless this is a TURN server. */
    public function getPassword(): ?string {}

    /** Null unless this is a TURN server. */
    public function getRelayType(): ?RelayType {}

    public function isTurn(): bool {}
}

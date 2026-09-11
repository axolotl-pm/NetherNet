<?php

/**
 * @generate-class-entries
 * @generate-legacy-arginfo 80100
 */

namespace pmmp\webrtc;

/**
 * A single ICE candidate, as carried over signaling for trickle ICE.
 *
 * Instances are immutable and must be created through create().
 *
 * @not-serializable
 */
final class IceCandidate
{
    private function __construct() {}

    /**
     * The candidate line is accepted with or without its "a=" prefix. A null
     * media identifier lets the connection fill one in from the remote
     * description, which is what a peer with a single bundled m-line wants.
     *
     * @throws WebRtcException if the candidate line cannot be parsed
     */
    public static function create(string $candidate, ?string $mid = null): IceCandidate {}

    public function getCandidate(): string {}

    /** Falls back to "0" when the candidate carries no explicit identifier. */
    public function getMid(): string {}
}

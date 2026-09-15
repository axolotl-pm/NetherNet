<?php

/**
 * @generate-class-entries
 * @generate-legacy-arginfo 80100
 */

namespace pmmp\webrtc;

/**
 * Configure process-wide SCTP heartbeat and retransmission settings.
 *
 * These settings apply to all newly established SCTP associations.
 * Passing null resets the parameter to the libdatachannel default.
 * All timeouts are in milliseconds.
 *
 * @param int|null $heartbeatInterval Heartbeat interval in milliseconds (default: 10000)
 * @param int|null $maxRetransmitAttempts Maximum consecutive retransmit attempts before aborting (default: 5)
 * @param int|null $minRetransmitTimeout Minimum retransmission timeout in milliseconds (default: 200)
 * @param int|null $maxRetransmitTimeout Maximum retransmission timeout in milliseconds (default: 10000)
 * @param int|null $initialRetransmitTimeout Initial retransmission timeout in milliseconds (default: 1000)
 * @throws \ValueError if a value is outside 1..4294967295
 */
function set_sctp_settings(
    ?int $heartbeatInterval = null,
    ?int $maxRetransmitAttempts = null,
    ?int $minRetransmitTimeout = null,
    ?int $maxRetransmitTimeout = null,
    ?int $initialRetransmitTimeout = null,
): void {}

<?php

/**
 * @generate-class-entries
 * @generate-legacy-arginfo 80200
 */

namespace pmmp\webrtc;

/**
 * Progress of local ICE candidate gathering.
 *
 * With non-trickle ICE the local description stays incomplete until this
 * reaches COMPLETE, because candidates are only included once gathering has
 * finished.
 */
enum GatheringState: int
{
    case NOT_STARTED = 0;
    case IN_PROGRESS = 1;
    case COMPLETE = 2;
}

enum ConnectionState: int
{
    case NOT_STARTED = 0;
    case CONNECTING = 1;
    case CONNECTED = 2;
    case DISCONNECTED = 3;
    case FAILED = 4;
    case CLOSED = 5;
}

/**
 * Transport used when relaying through a TURN server.
 */
enum RelayType: int
{
    case UDP = 0;
    case TCP = 1;
    case TLS = 2;
}

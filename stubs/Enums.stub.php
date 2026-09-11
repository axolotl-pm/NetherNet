<?php

/**
 * @generate-class-entries
 * @generate-legacy-arginfo 80100
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

/**
 * Where the connection sits in the offer/answer exchange.
 *
 * An offerer goes STABLE -> HAVE_LOCAL_OFFER -> STABLE, an answerer goes
 * STABLE -> HAVE_REMOTE_OFFER -> STABLE, which is also how to tell whether
 * getLocalDescription() is currently an offer or an answer.
 */
enum SignalingState: int
{
    case STABLE = 0;
    case HAVE_LOCAL_OFFER = 1;
    case HAVE_REMOTE_OFFER = 2;
    case HAVE_LOCAL_PRANSWER = 3;
    case HAVE_REMOTE_PRANSWER = 4;
}

/**
 * Which candidate types a connection is allowed to use.
 *
 * RELAY forces every path through a TURN server, which is what a peer behind a
 * NAT that refuses to be traversed is left with, and it also keeps the local
 * addresses out of the candidates the other side sees.
 */
enum TransportPolicy: int
{
    case ALL = 0;
    case RELAY = 1;
}

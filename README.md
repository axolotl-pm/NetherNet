# NetherNet
[![CI](https://github.com/axolotl-pm/NetherNet/actions/workflows/ci.yml/badge.svg)](https://github.com/axolotl-pm/NetherNet/actions/workflows/ci.yml)

A server-side implementation of [**NetherNet**](https://mojang.github.io/bedrock-protocol-docs/guides/nether-net-onboarding-guide/), the WebRTC DataChannel transport used by Minecraft: Bedrock Edition, in PHP.

It is built on `ext-webrtc`, which provides bindings for [libdatachannel](https://github.com/paullouisageneau/libdatachannel).

## Requirements
- PHP 8.1 or newer (64-bit) with IPv6 support
- [`ext-webrtc`](https://github.com/axolotl-pm/ext-webrtc)
- [`ext-encoding`](https://github.com/pmmp/ext-encoding)
- `ext-openssl`, `ext-hash`, `ext-json`, `ext-sockets`

## What's in the library?
- [`NetherNetServer`](src/server/NetherNetServer.php): the entry point. Accepts clients, owns their sessions, and is driven by calling `tick()`.
- [`ServerEventListener`](src/server/ServerEventListener.php): implemented by you to receive session open/close events and incoming packets.
- Signaling: [`HttpSignaling`](src/signaling/http/HttpSignaling.php) (online play) and [`LanSignaling`](src/signaling/lan/LanSignaling.php) (LAN discovery), registered with `addSignaling()`.
- Identity: [`ServerIdentity`](src/identity/ServerIdentity.php) and [`SelfSignedIdentityProvider`](src/identity/SelfSignedIdentityProvider.php) for the server's identity, and [`PeerIdentity`](src/identity/PeerIdentity.php) for verified clients.
- Address blocking: [`blockAddress()`](src/server/NetherNetServer.php) and `unblockAddress()`.

## Usage
[examples/server.php](examples/server.php) is a complete echo server showing how to set everything up and run the tick loop.

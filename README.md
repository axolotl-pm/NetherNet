# NetherNet

A PHP implementation of **NetherNet**, the WebRTC DataChannel transport used by Minecraft: Bedrock Edition. Built on [ext-webrtc](https://github.com/axolotl-pm/ext-webrtc).

> [!WARNING]
> If you're from PocketMine-MP, the API is not shielded by API constraints because it's part of network layer.
> Expect things to be changed over release.

## Requirements

- PHP 8.1 or newer (64-bit) with IPv6 support
- [`ext-webrtc`](https://github.com/axolotl-pm/ext-webrtc)
- [`ext-encoding`](https://github.com/pmmp/ext-encoding)
- `ext-openssl`, `ext-hash`, `ext-json`, `ext-sockets`

## Quick Start

[examples/server.php](examples/server.php) is a complete echo server: it creates a server identity, registers the HTTP and LAN signaling transports, and runs the tick loop. It is analysed by PHPStan together with the library, so it always matches the current API.

```bash
composer install
php examples/server.php
```

## Libraries used for creating project
- [go-nethernet](https://github.com/df-mc/go-nethernet): A Go implementation of NetherNet, used as a reference for protocol details.
- [ext-webrtc](https://github.com/axolotl-pm/ext-webrtc): A PHP bindings for DataChannel, used for implementing the transport layer of NetherNet.

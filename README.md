# NetherNet

A PHP implementation of **NetherNet**, the WebRTC DataChannel transport used by Minecraft: Bedrock Edition. Built on [ext-webrtc](https://github.com/axolotl-pm/ext-webrtc).

> [!WARNING]
> Experimental! The API is not finalized yet and is not shielded by API constraints.
> Expect things to be changed over release.

## Requirements

- PHP 8.1 or newer (64-bit)
- `ext-webrtc`
- `ext-openssl`, `ext-json`, `ext-sockets`

## Quick Start

```php
use pocketmine\nethernet\discovery\LanSignaling;
use pocketmine\nethernet\discovery\MutableServerDataProvider;
use pocketmine\nethernet\discovery\ServerData;
use pocketmine\nethernet\identity\ServerIdentity;
use pocketmine\nethernet\NetherNetServer;
use pocketmine\nethernet\ServerConfiguration;
use pocketmine\nethernet\ServerEventListener;
use pocketmine\nethernet\session\DisconnectReason;
use pocketmine\nethernet\session\Reliability;
use pocketmine\nethernet\session\Session;
use pocketmine\nethernet\signaling\http\HttpSignaling;

// 1. Define event listener for client sessions
$listener = new class implements ServerEventListener{
    public function onSessionOpen(Session $session) : void{
        echo "Client connected: " . $session->getNetworkId() . PHP_EOL;
    }

    public function onPacketReceive(Session $session, string $payload, Reliability $reliability) : void{
        // Handle Minecraft packet payload
        $session->send($payload, $reliability);
    }

    public function onSessionClose(Session $session, DisconnectReason $reason) : void{
        echo "Client disconnected: " . $reason->getMessage() . PHP_EOL;
    }
};

// 2. Configure server identity and instantiate server
$identity = file_exists('server.key')
    ? ServerIdentity::fromPrivateKeyPem(file_get_contents('server.key'))
    : ServerIdentity::generate();

$config = new ServerConfiguration(identity: $identity);
$server = NetherNetServer::create($config, $listener);

// 3. Add signaling transports (HTTP and LAN)
$server->addSignaling(new HttpSignaling(
    negotiator: $server->getNegotiator(),
    address: '0.0.0.0',
    port: 19132
));

$serverDataProvider = new MutableServerDataProvider(
    ServerData::fromPongData("MCPE;Dedicated Server;800;1.21.100;0;20;0;World;Survival;1;19132;19133;")
);
$server->addSignaling(new LanSignaling(
    negotiator: $server->getNegotiator(),
    serverDataProvider: $serverDataProvider,
    networkId: 12345678
));

// 4. Start server and run tick loop
$server->start();

while($server->isRunning()){
    $server->tick();
    usleep(10_000); // 10ms tick
}
```

<?php

namespace CatPaw\Web\Interfaces;

use Amp\Websocket\Client;
use Amp\Websocket\Message;
use Amp\Websocket\Server\Gateway;
use Throwable;

interface WebSocketInterface {
    public function onStart(Gateway $gateway);

    public function onMessage(Message $message, Gateway $gateway, Client $client);

    public function onClose(Client $client, int $code, string $reason);

    public function onError(Throwable $e);
}

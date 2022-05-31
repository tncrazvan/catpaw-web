<?php

namespace CatPaw\Web\Interfaces;

use Amp\Promise;
use Amp\Websocket\Client;
use Amp\Websocket\Message;
use Amp\Websocket\Server\Gateway;
use Throwable;

interface WebSocketInterface {
    public function onStart(Gateway $gateway):Promise;

    public function onMessage(Message $message, Gateway $gateway, Client $client):Promise;

    public function onClose(Client $client, int $code, string $reason):Promise;

    public function onError(Throwable $e):Promise;
}

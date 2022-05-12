<?php

namespace CatPaw\Web\Interface;

use Amp\Websocket\Client;
use Amp\Websocket\Message;
use Amp\Websocket\Server\Gateway;
use Throwable;

interface WebSocketInterface {

	public function onStart(Gateway $gateway);

	public function onMessage(Message $message, Gateway $gateway, Client $client);

	public function onClose(...$args);

	public function onError(Throwable $e);
}
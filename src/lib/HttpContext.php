<?php

namespace CatPaw\Web;

use Amp\ByteStream\InputStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use CatPaw\Web\Session\SessionOperationsInterface;

class HttpContext {
    public SessionOperationsInterface $sessionOperations;

    public string   $eventID;
    public array    $params;
    public array    $query;
    public Request  $request;
    public Response $response;
    /** @var mixed|InputStream */
    public mixed $prepared;
}

<?php

namespace CatPaw\Web;

use function Amp\ByteStream\buffer;
use Amp\ByteStream\InputStream;

use function Amp\call;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Success;
use Amp\Websocket\Client;
use Amp\Websocket\Code;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Gateway;
use Amp\Websocket\Server\Websocket;
use CatPaw\Utilities\Caster;
use CatPaw\Utilities\Container;
use CatPaw\Utilities\XMLSerializer;
use CatPaw\Web\Attributes\Consumes;
use CatPaw\Web\Attributes\Produces;
use CatPaw\Web\Exceptions\ContentTypeRejectedException;
use CatPaw\Web\Interfaces\WebSocketInterface;
use CatPaw\Web\Session\SessionOperationsInterface;
use CatPaw\Web\Utilities\Route;
use Closure;
use Exception;
use function explode;
use Generator;
use function implode;
use function in_array;
use function is_array;
use function is_object;
use function json_encode;
use ReflectionException;
use ReflectionFunction;
use SplFixedArray;
use stdClass;
use Throwable;

class HttpInvoker {
    /**
     * @param SessionOperationsInterface $sessionOperations
     * @param false|Response             $badRequestNoContentType
     * @param false|Response             $badRequestCantConsume
     */
    public function __construct(
        private SessionOperationsInterface $sessionOperations,
        private false|Response $badRequestNoContentType = false,
        private false|Response $badRequestCantConsume = false,
    ) {
        if (!$this->badRequestNoContentType) {
            $this->badRequestNoContentType = new Response(Status::BAD_REQUEST, [], '');
        }
        if (!$this->badRequestCantConsume) {
            $this->badRequestCantConsume = new Response(Status::BAD_REQUEST, [], '');
        }
    }

    /**
     * @param  Request             $httpRequest
     * @param  string              $httpRequestPath
     * @param  string              $httpRequestMethod
     * @param  array               $httpRequestPathParameters
     * @throws Throwable
     * @throws ReflectionException
     * @return Generator
     */
    public function invoke(
        Request $httpRequest,
        string $httpRequestMethod,
        string $httpRequestPath,
        array $httpRequestPathParameters,
    ): Generator {
        /** @var HttpConfiguration $config */
        $httpConfiguration = yield Container::create(HttpConfiguration::class);
        $httpRequestContentType = $httpRequest->getHeader("Content-Type") ?? '*/*';
        $callbacks = Route::findRoute($httpRequestMethod, $httpRequestPath);
        $len = count($callbacks);


        $queryChunks = explode('&', preg_replace('/^\?/', '', $httpRequest->getUri()->getQuery(), 1));
        $query = [];

        foreach ($queryChunks as $chunk) {
            $split = explode('=', $chunk);
            $len = count($split);
            if (2 === $len) {
                $query[urldecode($split[0])] = urldecode($split[1]);
            } elseif (1 === $len && '' !== $split[0]) {
                $query[urldecode($split[0])] = true;
            }
        }

        /** @var HttpContext $http */
        $context = new class(sessionOperations: $this->sessionOperations, eventID: "$httpRequestMethod:$httpRequestPath", query: $query, params: $httpRequestPathParameters, request: $httpRequest, response: new Response(), prepared: false) extends HttpContext {
            public function __construct(
                public SessionOperationsInterface $sessionOperations,
                public string $eventID,
                public array $params,
                public array $query,
                public Request $request,
                public Response $response,
                /** @var mixed|InputStream */
                public mixed $prepared,
            ) {
            }
        };


        for ($i = 0; $i < $len; $i++) {
            $continue = yield from $this->next(
                configuration: $httpConfiguration,
                context: $context,
                requestMethod: $httpRequestMethod,
                requestPath: $httpRequestPath,
                requestContentType: $httpRequestContentType,
                index: $i,
                callback: $callbacks[$i],
            );

            if (!$continue && $len > $i + 1) {
                //a filter just interrupted the response.
                $this->contextualize($context);
                return $context->response;
            }
        }

        $this->contextualize($context);
        return $context->response;
    }

    private function contextualize(HttpContext $context) {
        $acceptables = explode(",", $context->request->getHeader("Accept") ?? "text/plain");
        $produced = [$context->response->getHeader("content-type") ?? "text/plain"];

        foreach ($acceptables as $acceptable) {
            if (str_starts_with($acceptable, "*/*")) {
                $context->response->setHeader("Content-Type", $produced[0]);
                $this->transform($context, $produced[0]);
                return;
            }
            if (in_array($acceptable, $produced)) {
                $context->response->setHeader("Content-Type", $acceptable);
                $this->transform($context, $acceptable);
                return;
            }
        }
    }

    /**
     * @param  string $contentType
     * @param  mixed  $value
     * @return string
     */
    private function transform(
        HttpContext $context,
        string $contentType,
    ): void {
        if ($context->prepared instanceof InputStream || $context->prepared instanceof Websocket) {
            $context->response->setBody($context->prepared);
            return;
        }

        $context->response->setBody(
            match ($contentType) {
                'application/json' => json_encode($context->prepared),
                'application/xml', 'text/xml' => is_array($context->prepared)
                ? XMLSerializer::generateValidXmlFromArray($$context->prepared) ?? ""
                : (is_object($context->prepared)
                ? (
                    XMLSerializer::generateValidXmlFromObj(
                        Caster::cast($$context->prepared, stdClass::class)
                    ) ?? ""
                )
                : XMLSerializer::generateValidXmlFromArray([$$context->prepared]) ?? ""),
                default => $context->prepared
            }
        );
    }

    private array $cache = [];
    private const REFLECTION = 0;
    private const CONSUMES = 1;
    private const PRODUCES = 2;

    /**
     * @param  HttpConfiguration   $configuration
     * @param  HttpContext         $context
     * @param  string              $requestMethod
     * @param  string              $requestPath
     * @param  string              $requestContentType
     * @param  Closure             $callback
     * @param  bool                $isMiddleware
     * @throws ReflectionException
     * @throws Throwable
     * @return Generator
     */
    private function next(
        HttpConfiguration $configuration,
        HttpContext $context,
        string $requestMethod,
        string $requestPath,
        string $requestContentType,
        int $index,
        Closure $callback,
    ): Generator {
        if (!isset($this->cache[$requestMethod][$requestPath])) {
            $reflection = new ReflectionFunction($callback);
            $consumes = yield Consumes::findByFunction($reflection);
            $produces = yield Produces::findByFunction($reflection);

            $this->cache[$requestMethod][$requestPath] = new SplFixedArray(3);

            $this->cache[$requestMethod][$requestPath][self::REFLECTION] = $reflection;
            $this->cache[$requestMethod][$requestPath][self::CONSUMES] = $consumes;
            $this->cache[$requestMethod][$requestPath][self::PRODUCES] = $produces;
        }

        /** @var ReflectionFunction $reflection */
        $reflection = $this->cache[$requestMethod][$requestPath][self::REFLECTION];
        /** @var Consumes $consumes */
        $consumes = $this->cache[$requestMethod][$requestPath][self::CONSUMES];
        /** @var Produces $produces */
        $produces = $this->cache[$requestMethod][$requestPath][self::PRODUCES];


        /** @var false|Consumes $produces */
        if ($consumes) {
            $consumables = $this->filterConsumedContentType($consumes);
            $isConsumable = false;

            foreach ($consumables as $consumable) {
                if ($requestContentType === $consumable) {
                    $isConsumable = true;
                    break;
                }
            }

            if (!$isConsumable) {
                $consumableString = implode(',', $consumables);
                throw new Exception(
                    message: "Bad request on '$requestMethod $requestPath', "
                        ."can only consume Content-Type '$consumableString'; provided '$requestContentType'."
                );
            }
        }


        try {
            $dependencies = yield Container::dependencies($reflection, [
                "id" => ["$index#".$context->request->getMethod(), $context->request->getUri()->getPath()],
                "force" => [
                    Request::class => $context->request,
                    Response::class => $context->response,
                ],
                "context" => $context,
            ]);
        } catch (ContentTypeRejectedException $e) {
            $message = '';
            if ($configuration->httpShowExceptions) {
                $message .= $e->getMessage();
                if ($configuration->httpShowStackTrace) {
                    $message .= PHP_EOL.$e->getTraceAsString();
                }
            }
            echo $e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL;
            $context->response = new Response(Status::BAD_REQUEST, [], $message);
            return true;
        }
        
        /** @var WebSocketInterface|Response|string|int|float|bool */
        $response = yield \Amp\call($callback, ...$dependencies);

        if (($sessionIDCookie = $context->response->getCookie("session-id") ?? false)) {
            yield $this->sessionOperations->persistSession($sessionIDCookie->getValue());
        }


        if (!$response) {
            return false;
        }

        if ($response instanceof WebSocketInterface) {
            $context->response = $this->websocket($response)->handleRequest($context->request);
            $context->prepared = $$context->response->getBody();
        } else {
            if (!$response instanceof Response) {
                // $context->response->setBody($response);
                $context->prepared = $response;
            } else {
                /** @var Response $response */
                foreach ($response->getHeaders() as $key => $value) {
                    $context->response->setHeader($key, $value);
                }
                
                $context->response->setStatus($response->getStatus());
                // $context->response->setBody($response->getBody());
                $context->prepared = $response->getBody();
            }
        }
        return true;
    }

    private function websocket(WebSocketInterface $wsi): Websocket {
        $websocket = new Websocket(new class($wsi) implements ClientHandler {
            public function __construct(private WebSocketInterface $wsi) {
            }

            public function handleHandshake(Gateway $gateway, Request $request, Response $response): Promise {
                return call(function() use ($gateway, $request, $response) {
                    try {
                        yield \Amp\call(fn() => $this->wsi->onStart($gateway));
                    } catch (Throwable $e) {
                        yield \Amp\call(fn() => $this->wsi->onError($e));
                    }
                    return new Success($response);
                });
            }

            public function handleClient(Gateway $gateway, Client $client, Request $request, Response $response): Promise {
                return call(function() use ($gateway, $client): Generator {
                    try {
                        while ($message = yield $client->receive()) {
                            yield \Amp\call(fn() => $this->wsi->onMessage($message, $gateway, $client));
                        }

                        try {
                            $client->onClose(fn(...$args) => yield \Amp\call(fn() => $this->wsi->onClose(...$args)));
                        } catch (Throwable $e) {
                            yield \Amp\call(fn() => $this->wsi->onError($e));
                            $client->close(Code::ABNORMAL_CLOSE);
                        }
                    } catch (Throwable $e) {
                        yield \Amp\call(fn() => $this->wsi->onError($e));
                        $client->close(Code::ABNORMAL_CLOSE);
                    }
                });
            }
        });

        $websocket->onStart(WebServer::getHttpServer());
        $websocket->onStop(WebServer::getHttpServer());

        return $websocket;
    }

    private function filterConsumedContentType(Consumes $consumes): array {
        $consumed = $consumes->getContentType();
        return array_filter($consumed, fn($type) => !empty($type));
    }
}

<?php

namespace CatPaw\Web;

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
     * @param  Request             $request
     * @param  string              $requestMethod
     * @param  string              $requestPath
     * @param  array               $requestPathParameters
     * @throws Throwable
     * @throws ReflectionException
     * @return Generator
     */
    public function invoke(
        Request $request,
        string $requestMethod,
        string $requestPath,
        array $requestPathParameters,
        HttpConfiguration $configuration,
    ): Generator {
        // /** @var HttpConfiguration $config */
        // $configuration      = yield Container::create(HttpConfiguration::class);
        $requestContentType = $request->getHeader("Content-Type") ?? '*/*';
        $callbacks          = Route::findRoute($requestMethod, $requestPath);
        $len                = count($callbacks);


        $queryChunks = explode('&', preg_replace('/^\?/', '', $request->getUri()->getQuery(), 1));
        $query       = [];

        foreach ($queryChunks as $chunk) {
            $split = explode('=', $chunk);
            $l     = count($split);
            if (2 === $l) {
                $query[urldecode($split[0])] = urldecode($split[1]);
            } elseif (1 === $l && '' !== $split[0]) {
                $query[urldecode($split[0])] = true;
            }
        }

        $eventState = new EventState([]);

        $response = new Response();
        
        for ($i = 0; $i < $len; $i++) {
            $reflection = Route::findReflection($requestMethod, $requestPath, $i);
            $consumes   = Route::findConsumes($requestMethod, $requestPath, $i);
            $produces   = Route::findProduces($requestMethod, $requestPath, $i);

            /** @var HttpContext $http */
            $context = new class(sessionOperations: $this->sessionOperations, eventID: "$requestMethod:$requestPath", query: $query, params: $requestPathParameters[$i] ?? [], request: $request, response: $response, prepared: false) extends HttpContext {
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


            $continue = yield from $this->next(
                configuration     : $configuration,
                context           : $context,
                requestMethod     : $requestMethod,
                requestPath       : $requestPath,
                requestContentType: $requestContentType,
                index             : $i,
                max               : $len - 1,
                callback          : $callbacks[$i],
                reflection		  : $reflection,
                consumes		  : $consumes,
                eventState        : $eventState,
            );

            if (!$continue && $i < $len - 1) {
                //a filter just interrupted the response.
                $this->contextualize(
                    context : $context,
                    produces: $produces
                );
                return $context->response;
            }
        }

        $this->contextualize(
            context : $context,
            produces: Route::findProduces($requestMethod, $requestPath, $i - 1)
        );
        return $context->response;
    }

    private function contextualize(HttpContext $context, false|Produces $produces): void {
        if (!$context->response->hasHeader("Content-Type")) {
            if ($produces) {
                $context->response->setHeader("Content-Type", $produces->getContentType() ?? ["text/plain"]);
            }
        }

        $produced = $context->response->getHeaderArray("Content-Type");
        if (0 === count($produced)) {
            $produced = ["text/plain"];
        }

        $acceptables = explode(",", $context->request->getHeader("Accept") ?? "text/plain");

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
     * @param  HttpContext $context
     * @param  string      $contentType
     * @return void
     */
    private function transform(
        HttpContext $context,
        string $contentType,
    ): void {
        if ($context->prepared instanceof InputStream || $context->prepared instanceof Websocket) {
            $context->response->setBody($context->prepared);
            return;
        }

        $isarray  = is_array($context->prepared);
        $isobject = $isarray?false:is_object($context->prepared);
        
        $context->response->setBody(
            match ($contentType) {
                'application/json' => $isarray || $isobject ? json_encode($context->prepared): $context->prepared,
                'application/xml', 'text/xml' => $isarray
                    ? XMLSerializer::generateValidXmlFromArray($$context->prepared) ?? ""
                    : ($isobject
                        ? (
                            XMLSerializer::generateValidXmlFromObj(
                                Caster::cast($$context->prepared, stdClass::class)
                            ) ?? ""
                        )
                        : XMLSerializer::generateValidXmlFromArray([$$context->prepared]) ?? ""),
                default => $isarray || $isobject?json_encode($context->prepared):$context->prepared
            }
        );
    }

    /**
     * @param  HttpConfiguration $configuration
     * @param  HttpContext       $context
     * @param  string            $requestMethod
     * @param  string            $requestPath
     * @param  string            $requestContentType
     * @param  int               $index
     * @param  Closure           $callback
     * @throws Exception
     * @return Generator
     */
    private function next(
        HttpConfiguration $configuration,
        HttpContext $context,
        string $requestMethod,
        string $requestPath,
        string $requestContentType,
        int $index,
        int $max,
        Closure $callback,
        ReflectionFunction $reflection,
        false|Consumes $consumes,
        EventState $eventState,
    ): Generator {

        /** @var false|Consumes $produces */
        if ($consumes) {
            $consumables  = $this->filterConsumedContentType($consumes);
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
                "id"    => ["$index#".$context->request->getMethod(), $context->request->getUri()->getPath()],
                "force" => [
                    Request::class    => $context->request,
                    Response::class   => $context->response,
                    EventState::class => $eventState,
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

        /** @var WebSocketInterface|EventState|Response|string|int|float|bool */
        $response = yield call(fn() => $callback(...$dependencies));
        

        if ($index < $max && $response) {
            if (!$response instanceof EventState) {
                if ($response instanceof Response) {
                    foreach ($response->getHeaders() as $key => $value) {
                        $context->response->setHeader($key, $value);
                    }
                    $context->response->setStatus($response->getStatus());
                    $context->prepared = $response->getBody();
                } else {
                    $context->prepared = $response;
                }
                return false;
            }

            $eventState->set([
                ...$eventState->get(),
                ...$response->get(),
            ]);
        }

        if (($sessionIDCookie = $context->response->getCookie("session-id") ?? $context->request->getCookie("session-id") ?? false)) {
            yield $this->sessionOperations->persistSession($sessionIDCookie->getValue());
        }


        // if (!$response) {
        //     return false;
        // }

        if ($response instanceof WebSocketInterface) {
            try {
                /** @var Websocket $websocket */
                $websocket         = yield $this->websocket($response);
                $context->response = yield $websocket->handleRequest($context->request);
                $context->prepared = $context->response->getBody();
            } catch (Throwable $e) {
                $context->response = new Response(Status::BAD_REQUEST, [], "Bad request, this api serves websockets.");
                $context->prepared = $context->response->getBody();
                return false;
            }
        } else {
            if (!$response instanceof Response) {
                // $context->response->setBody($response);
                $context->prepared = $response;
            } else {
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

    /**
     *
     * @param  WebSocketInterface $wsi
     * @return Promise<Websocket>
     */
    private function websocket(WebSocketInterface $wsi): Promise {
        return call(function() use ($wsi) {
            $websocket = new Websocket(new class($wsi) implements ClientHandler {
                public function __construct(private WebSocketInterface $wsi) {
                }

                public function handleHandshake(Gateway $gateway, Request $request, Response $response): Promise {
                    return call(function() use ($gateway, $request, $response) {
                        try {
                            yield call($this->wsi->onStart(...), $gateway);
                        } catch (Throwable $e) {
                            yield call($this->wsi->onError(...), $e);
                        }
                        return new Success($response);
                    });
                }

                public function handleClient(Gateway $gateway, Client $client, Request $request, Response $response): Promise {
                    $client->onClose(fn(Client $client, int $code, string $reason) => call($this->wsi->onClose(...), $client, $code, $reason));
                    return call(function() use ($gateway, $client) {
                        try {
                            while ($message = yield $client->receive()) {
                                yield call($this->wsi->onMessage(...), $message, $gateway, $client);
                            }

                            try {
                                $client->close(Code::ABNORMAL_CLOSE);
                            } catch (Throwable $e) {
                                yield call($this->wsi->onError(...), $e);
                            }
                        } catch (Throwable $e) {
                            yield call($this->wsi->onError(...), $e);
                        }
                    });
                }
            });

            yield $websocket->onStart(WebServer::getHttpServer());
            yield $websocket->onStop(WebServer::getHttpServer());

            return $websocket;
        });
    }

    private function filterConsumedContentType(Consumes $consumes): array {
        $consumed = $consumes->getContentType();
        return array_filter($consumed, fn($type) => !empty($type));
    }
}

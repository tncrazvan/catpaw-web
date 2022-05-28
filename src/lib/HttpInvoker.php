<?php

namespace CatPaw\Web;

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
use Generator;
use ReflectionException;
use ReflectionFunction;
use SplFixedArray;
use stdClass;
use Throwable;
use function Amp\call;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_object;
use function json_encode;

class HttpInvoker {
	/**
	 * @param SessionOperationsInterface $sessionOperations
	 * @param false|Response             $badRequestNoContentType
	 * @param false|Response             $badRequestCantConsume
	 */
	public function __construct(
		private SessionOperationsInterface $sessionOperations,
		private false|Response             $badRequestNoContentType = false,
		private false|Response             $badRequestCantConsume = false,
	) {
		if(!$this->badRequestNoContentType) {
			$this->badRequestNoContentType = new Response(Status::BAD_REQUEST, [], '');
		}
		if(!$this->badRequestCantConsume) {
			$this->badRequestCantConsume = new Response(Status::BAD_REQUEST, [], '');
		}
	}

	/**
	 * @param Request $httpRequest
	 * @param string  $httpRequestPath
	 * @param string  $httpRequestMethod
	 * @param array   $httpRequestPathParameters
	 * @return Generator
	 * @throws Throwable
	 * @throws ReflectionException
	 */
	public function invoke(
		Request $httpRequest,
		string  $httpRequestMethod,
		string  $httpRequestPath,
		array   $httpRequestPathParameters,
	): Generator {
		/** @var HttpConfiguration $config */
		$httpConfiguration = yield Container::create(HttpConfiguration::class);
		$httpRequestContentType = $httpRequest->getHeader("Content-Type")??'*/*';
		$callbacks = Route::findRoute($httpRequestMethod, $httpRequestPath);
		$len = count($callbacks);


		$queryChunks = explode('&', preg_replace('/^\?/', '', $httpRequest->getUri()->getQuery(), 1));
		$query = [];

		foreach($queryChunks as $chunk) {
			$split = explode('=', $chunk);
			$len = count($split);
			if(2 === $len) {
				$query[urldecode($split[0])] = urldecode($split[1]);
			} elseif(1 === $len && '' !== $split[0]) {
				$query[urldecode($split[0])] = true;
			}
		}

		/** @var HttpContext $http */
		$httpContext = new class(
			sessionOperations: $this->sessionOperations,
			eventID          : "$httpRequestMethod:$httpRequestPath",
			query            : $query,
			params           : $httpRequestPathParameters,
			request          : $httpRequest,
			response         : new Response(),
		) extends HttpContext {
			public function __construct(
				public SessionOperationsInterface $sessionOperations,
				public string                     $eventID,
				public array                      $params,
				public array                      $query,
				public Request                    $request,
				public Response                   $response,
			) {
			}
		};


		for($i = 0; $i < $len; $i++) {
			/** @var true|Response $httpResponse */
			$httpResponse = yield from $this->next(
				httpConfiguration     : $httpConfiguration,
				httpContext           : $httpContext,
				httpRequest           : $httpRequest,
				httpRequestMethod     : $httpRequestMethod,
				httpRequestPath       : $httpRequestPath,
				httpRequestContentType: $httpRequestContentType,
				callback              : $callbacks[$i],
				isMiddleware          : $i < $len - 1
			);

			if(true !== $httpResponse) {
				return $httpResponse;
			}
		}
	}

	private array $cache = [];
	private const REFLECTION = 0;
	private const CONSUMES   = 1;
	private const PRODUCES   = 2;

	/**
	 * @param HttpConfiguration $httpConfiguration
	 * @param HttpContext       $httpContext
	 * @param Request           $httpRequest
	 * @param string            $httpRequestMethod
	 * @param string            $httpRequestPath
	 * @param string            $httpRequestContentType
	 * @param Closure           $callback
	 * @param bool              $isMiddleware
	 * @return Generator
	 * @throws ReflectionException
	 * @throws Throwable
	 */
	private function next(
		HttpConfiguration $httpConfiguration,
		HttpContext       $httpContext,
		Request           $httpRequest,
		string            $httpRequestMethod,
		string            $httpRequestPath,
		string            $httpRequestContentType,
		Closure           $callback,
		bool              $isMiddleware
	): Generator {
		if(!isset($this->cache[$httpRequestMethod][$httpRequestPath])) {
			$reflection = new ReflectionFunction($callback);
			$consumes = yield Consumes::findByFunction($reflection);
			$produces = yield Produces::findByFunction($reflection);

			$this->cache[$httpRequestMethod][$httpRequestPath] = new SplFixedArray(3);

			$this->cache[$httpRequestMethod][$httpRequestPath][self::REFLECTION] = $reflection;
			$this->cache[$httpRequestMethod][$httpRequestPath][self::CONSUMES] = $consumes;
			$this->cache[$httpRequestMethod][$httpRequestPath][self::PRODUCES] = $produces;
		}

		/** @var ReflectionFunction $reflection */
		$reflection = $this->cache[$httpRequestMethod][$httpRequestPath][self::REFLECTION];
		/** @var Consumes $consumes */
		$consumes = $this->cache[$httpRequestMethod][$httpRequestPath][self::CONSUMES];
		/** @var Produces $produces */
		$produces = $this->cache[$httpRequestMethod][$httpRequestPath][self::PRODUCES];


		/** @var false|Consumes $produces */
		if($consumes) {
			$consumables = $this->filterConsumedContentType($consumes);
			$canConsume = false;
			foreach($consumables as $consumesItem) {
				if($httpRequestContentType === $consumesItem) {
					$canConsume = true;
					break;
				}
			}
			if(!$canConsume) {
				$consumableString = implode(',', $consumables);
				throw new Exception(
					message: "Bad request on '$httpRequestMethod $httpRequestPath', "
							 ."can only consume Content-Type '$consumableString'; provided '$httpRequestContentType'."
				);
			}
		}


		try {
			$parameters = yield Container::dependencies($reflection, [
				"id"      => [$httpContext->request->getMethod(), $httpContext->request->getUri()->getPath()],
				"force"   => [
					Request::class  => $httpContext->request,
					Response::class => $httpContext->response,
				],
				"context" => $httpContext,
			]);
		} catch(ContentTypeRejectedException $e) {
			$message = '';
			if($httpConfiguration->httpShowExceptions) {
				$message .= $e->getMessage();
				if($httpConfiguration->httpShowStackTrace) {
					$message .= PHP_EOL.$e->getTraceAsString();
				}
			}
			echo $e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL;
			return new Response(Status::BAD_REQUEST, [], $message);
		}


		$value = yield \Amp\call($callback, ...$parameters);

		if(($sessionIDCookie = $httpContext->response->getCookie("session-id")??false)) {
			yield $this->sessionOperations->persistSession($sessionIDCookie->getValue());
		}

		if($isMiddleware && true === $value) {
			return true;
		}

		if($value instanceof WebSocketInterface) {
			return $this->websocket($value)->handleRequest($httpRequest);
		}

		return $this->response(
			httpContext: $httpContext,
			produces   : $produces,
			value      : $value,
		);
	}

	/**
	 * @throws Throwable
	 */
	private function response(
		HttpContext    $httpContext,
		false|Produces $produces,
		mixed          $value,
	): Response {
		if($value instanceof Response) {
			foreach($httpContext->response->getHeaders() as $k => $v) {
				if(!$value->hasHeader($k)) {
					$value->setHeader($k, $v);
				}
			}

			foreach($httpContext->response->getCookies() as $cookie) {
				$value->setCookie($cookie);
			}

			$value->setStatus($value->getStatus());

			$httpContext->response = $value;
			$this->adaptResponse($httpContext, $produces, false, true);
		} else {
			$this->adaptResponse($httpContext, $produces, $value);
		}

		return $httpContext->response;
	}

	private function adaptResponse(
		HttpContext    $httpContext,
		false|Produces $produces,
		mixed          $value,
		bool           $ignoreValue = false
	): void {
		$any = false;

		$acceptable = explode(",", $httpContext->request->getHeader("Accept")??"text/plain");
		$producedContentTypes = $produces ? $produces->getContentType() : [$httpContext->response->getHeader("content-type")??"text/plain"];


		foreach($acceptable as $acceptableContentType) {
			if(str_starts_with($acceptableContentType, "*/*")) {
				$any = true;
				continue;
			}
			if(in_array($acceptableContentType, $producedContentTypes)) {
				if($value) {
					$httpContext->response->setBody($this->transformResponseBody($acceptableContentType, $value));
				}

				$httpContext->response->setHeader("Content-Type", $acceptableContentType);
				return;
			}
		}


		if($any) {
			if(!$ignoreValue) {
				$httpContext->response->setBody($this->transformResponseBody($producedContentTypes[0], $value));
			}
			$httpContext->response->setHeader("Content-Type", $producedContentTypes[0]);
		}
	}

	/**
	 * @param string $contentType
	 * @param mixed  $value
	 * @return string
	 */
	private function transformResponseBody(
		string $contentType,
		mixed  $value,
	): string {
		return match ($contentType) {
			"application/json"            => json_encode($value)??"",
			"application/xml", "text/xml" => is_array($value)
				? XMLSerializer::generateValidXmlFromArray($value)??""
				: (is_object($value)
					? (
						XMLSerializer::generateValidXmlFromObj(
							Caster::cast($value, stdClass::class)
						)??""
					)
					: XMLSerializer::generateValidXmlFromArray([$value])??""),
			default                       => is_array($value) || is_object($value)
				? json_encode($value)??""
				: $value??""
		};
	}

	private function websocket(WebSocketInterface $wsi): Websocket {
		$websocket = new Websocket(new class($wsi) implements ClientHandler {
			public function __construct(private WebSocketInterface $wsi) {
			}

			public function handleHandshake(Gateway $gateway, Request $request, Response $response): Promise {
				return call(function() use ($gateway, $request, $response) {
					try {
						yield \Amp\call(fn() => $this->wsi->onStart($gateway));
					} catch(Throwable $e) {
						yield \Amp\call(fn() => $this->wsi->onError($e));
					}
					return new Success($response);
				});
			}

			public function handleClient(Gateway $gateway, Client $client, Request $request, Response $response): Promise {
				return call(function() use ($gateway, $client): Generator {
					try {
						while($message = yield $client->receive()) {
							yield \Amp\call(fn() => $this->wsi->onMessage($message, $gateway, $client));
						}

						try {
							$client->onClose(fn(...$args) => yield \Amp\call(fn() => $this->wsi->onClose(...$args)));
						} catch(Throwable $e) {
							yield \Amp\call(fn() => $this->wsi->onError($e));
							$client->close(Code::ABNORMAL_CLOSE);
						}
					} catch(Throwable $e) {
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

	private function filterConsumedContentType(
		Consumes $consumes,
	): array {
		$consumed = $consumes->getContentType();
		return array_filter($consumed, fn($type) => !empty($type));
	}
}

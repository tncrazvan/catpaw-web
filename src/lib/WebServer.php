<?php

namespace CatPaw\Web;

use Amp\Http\Server\ErrorHandler;
use CatPaw\Utilities\LoggerFactory;
use function Amp\call;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\LazyPromise;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\Server;
use Amp\Socket\ServerTlsContext;
use CatPaw\Utilities\Container;
use CatPaw\Utilities\Strings;
use CatPaw\Web\Attributes\Session;
use CatPaw\Web\Session\FileSystemSessionOperations;

use CatPaw\Web\Utilities\Route;
use function count;
use Exception;
use Generator;
use Parsedown;
use Psr\Log\LoggerInterface;
use Throwable;

class WebServer {
	private static bool $started = false;

	private static false|HttpServer $httpServer = false;

	private function __construct() {
	}


	public static function stop(): Promise {
		return call(function() {
			if(self::$httpServer)
				yield self::$httpServer->stop();
			self::$started = false;
			Route::clearAll();
		});
	}

	/**
	 *
	 * @param array|string       $interfaces
	 * @param array|string|false $secureInterfaces
	 * @param string             $webroot
	 * @param bool               $showStackTrace
	 * @param bool               $showExceptions
	 * @param array              $pemCertificates
	 * @param array              $headers
	 * @return Promise
	 */
	public static function start(
		array|string       $interfaces = "127.0.0.1:8080",
		array|string|false $secureInterfaces = false,
		string             $webroot = 'public',
		bool               $showStackTrace = false,
		bool               $showExceptions = false,
		array              $pemCertificates = [],
		array              $headers = [],
	): Promise {
		return call(function() use (
			$interfaces,
			$secureInterfaces,
			$webroot,
			$showStackTrace,
			$showExceptions,
			$pemCertificates,
			$headers,
		) {

			if(self::$started) {
				die(Strings::red("A web server is already running."));
			}

			self::$started = true;


			$config = new HttpConfiguration();

			Container::setObject(HttpConfiguration::class, $config);

			$config->pemCertificates = [];

			foreach($pemCertificates as $domain => $cert) {
				$file = $cert['file']??'';
				$key = $cert['key']??'';
				$config->pemCertificates[$domain] = new Certificate($file, $key);
			}

			$config->httpInterfaces = $interfaces;
			$config->httpSecureInterfaces = $secureInterfaces;
			$config->httpWebroot = $webroot;
			$config->httpShowStackTrace = $showStackTrace;
			$config->httpShowExceptions = $showExceptions;
			$config->headers = $headers;

			self::init($config);

			Session::setOperations(
				new FileSystemSessionOperations(
					ttl      : 1_440,
					dirname  : ".sessions",
					keepAlive: false,
				)
			);


			$config->mdp = new Parsedown();


			/** @var LoggerInterface */
			$logger = yield Container::create(LoggerInterface::class);
			if(!$logger) {
				$logger = LoggerFactory::create("DefaultLogger");
				Container::setObject(LoggerInterface::class, $logger);
				$logger->warning("The web library requires a logger, but it could not find one; injecting a default logger instead.");
			}

			$invoker = new HttpInvoker(Session::getOperations());

			$sockets = [];

			if(!is_iterable($config->httpInterfaces)) {
				$interfaces = [$config->httpInterfaces];
			} else {
				$interfaces = $config->httpInterfaces;
			}


			foreach($interfaces as $interface) {
				$sockets[] = Server::listen($interface);
			}

			if($config->pemCertificates) {
				$tlscontext = (new ServerTlsContext())
					->withCertificates($config->pemCertificates)
				;

				$context = (new BindContext())
					->withTlsContext($tlscontext)
				;

				foreach($context->getTlsContext()->getCertificates() as $certificate) {
					$logger->info("Using certificate file {$certificate->getCertFile()}");
					$logger->info("Using certificate key file {$certificate->getKeyFile()}");
				}


				$logger->info("Using CA path {$context->getTlsContext()->getCaPath()}");
				$logger->info("Using CA file {$context->getTlsContext()->getCaFile()}");


				if(!is_iterable($config->httpSecureInterfaces)) {
					$secureInterfaces = [$config->httpSecureInterfaces??[]];
				} else {
					$secureInterfaces = $config->httpSecureInterfaces;
				}

				foreach($secureInterfaces as $interface) {
					if($interface) {
						$sockets[] = Server::listen($interface, $context);
					}
				}
			} elseif($config->httpSecureInterfaces && count($config->httpSecureInterfaces) > 0) {
				$logger->critical("Server could not bind to the secure network interfaces because no pem certificate has been provided.");
			}

			if(0 >= count($sockets)) {
				$logger->error("At least one network interface must be provided in order to start the server.");
				die();
			}

			$server = self::$httpServer = new HttpServer(
				$sockets,
				new CallableRequestHandler(
					static fn(Request $request) => static::serve($config, $request, $invoker)
				),
				$logger
			);

			$server->setErrorHandler(new class() implements ErrorHandler {
				public function handleError(int $statusCode, string $reason = null, Request $request = null): Promise {
					return new LazyPromise(function() use ($statusCode, $reason, $request) {
					});
				}
			});

			yield $server->start();

			if(DIRECTORY_SEPARATOR === '/') {
				Loop::onSignal(\SIGINT, static function(string $watcherId) use ($server) {
					Loop::cancel($watcherId);
					yield $server->stop();
					Loop::stop();
					die(0);
				});
			}
		});
	}

	public static function getHttpServer(): false|HttpServer {
		return self::$httpServer;
	}

	private static function init(HttpConfiguration $config): void {
		Route::notFound(notfound($config));
	}


	/**
	 * @throws Exception
	 * @throws Throwable
	 */
	private static function serve(
		HttpConfiguration $config,
		Request           $httpRequest,
		HttpInvoker       $invoker
	): Generator {
		$logger = yield Container::create(LoggerInterface::class);
		$httpRequestMethod = $httpRequest->getMethod();
		$httpRequestUri = $httpRequest->getUri();
		$httpRequestPath = $httpRequestUri->getPath();

		//check if request matches any exposed endpoint and extract parameters
		[$httpRequestPath, $httpRequestPathParameters] = yield from static::usingPath($httpRequestMethod, $httpRequestPath, Route::getAllRoutes());

		if(!$httpRequestPath) {
			$response = yield from $invoker->invoke(
				httpRequest              : $httpRequest,
				httpRequestMethod        : $httpRequestMethod,
				httpRequestPath          : '@404',
				httpRequestPathParameters: $httpRequestPathParameters,
			);

			if(!$response) {
				$logger->error("There is no event listener or controller that manages \"404 Not Found\" requests, serving an empty \"500 Internal Server Error\" response instead.");
				$response = new Response(Status::INTERNAL_SERVER_ERROR);
			}
			return $response;
		}

		try {
			$response = yield from $invoker->invoke(
				httpRequest              : $httpRequest,
				httpRequestMethod        : $httpRequestMethod,
				httpRequestPath          : $httpRequestPath,
				httpRequestPathParameters: $httpRequestPathParameters,
			);

			if(!$response) {
				$logger->critical("The path matcher returned a match for \"$httpRequestMethod\" but the invoker couldn't find the function/method to invoke, serving an empty \"500 Internal Server Error\" response instead.");
				$response = new Response(Status::INTERNAL_SERVER_ERROR);
			}
			return $response;
		} catch(Throwable $e) {
			$message = $config->httpShowExceptions ? $e->getMessage() : '';
			$trace = $config->httpShowExceptions && $config->httpShowStackTrace ? "\n".$e->getTraceAsString() : '';
			$logger->error($e->getMessage());
			$logger->error($e->getTraceAsString());
			return new Response(500, [], $message.$trace);
		}
	}

	private static array $cache = [];

	private static function usingPath(string $httpRequestMethod, string $httpRequestPath, array $callbacks): Generator {
		if(!isset($callbacks[$httpRequestMethod])) {
			return [false, []];
		}
		foreach($callbacks[$httpRequestMethod] as $localPath => $callback) {
			if(!isset(self::$cache[$httpRequestMethod])) {
				self::$cache[$httpRequestMethod] = [];
			}
			if(isset(self::$cache[$httpRequestMethod][$localPath])) {
				$patterns = self::$cache[$httpRequestMethod][$localPath];
			} else {
				$patterns = [];
				foreach(Route::findPattern($httpRequestMethod,$localPath) as $g) {
					$patterns[] = yield from $g;
				}
				self::$cache[$httpRequestMethod][$localPath] = $patterns;
			}
			$ok = false;
			$allParams = [];
			/** @var callable $pattern */
			foreach($patterns as $pattern) {
				[$k, $params] = $pattern($httpRequestPath);
				if($k) {
					$ok = true;
					$allParams[] = $params;
				}
			}
			if($ok) {
				return [$localPath, $allParams];
			}
		}
		return [false, []];
	}
}

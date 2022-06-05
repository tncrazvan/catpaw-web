<?php

namespace CatPaw\Web;

use function Amp\call;
use Amp\Http\Server\ErrorHandler;
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
use CatPaw\Utilities\LoggerFactory;
use CatPaw\Web\Attributes\Session;
use CatPaw\Web\Session\FileSystemSessionOperations;

use CatPaw\Web\Utilities\Route;
use function count;

use Error;
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

    /**
     * 
     * @throws Error
     * @return Promise<LoggerInterface>
     */
    private static function logger():Promise {
        return call(function() {
            /** @var LoggerInterface */
            $logger = yield Container::create(LoggerInterface::class);
            if (!$logger) {
                $logger = LoggerFactory::create("DefaultLogger");
                Container::setObject(LoggerInterface::class, $logger);
                $logger->warning("The web library requires a logger, but it could not find one; injecting a default logger instead.");
            }
            return $logger;
        });
    }

    public static function stop(): Promise {
        return call(function() {
            /** @var LoggerInterface */
            $logger = yield self::logger();
            $logger->info("Attempting to stop server...");
            try {
                yield self::$httpServer->stop();
                self::$started = false;
            } catch (Throwable $e) {
                $logger->warning($e->getMessage());
            }
            Route::clearAll();
            Container::clearAll();
            $logger->info("Server stopped.");
        });
    }

    /**
     *
     * @param  array|string       $interfaces
     * @param  array|string|false $secureInterfaces
     * @param  string             $webroot
     * @param  bool               $showStackTrace
     * @param  bool               $showExceptions
     * @param  array              $pemCertificates
     * @param  array              $headers
     * @return Promise
     */
    public static function start(
        array|string $interfaces = "127.0.0.1:8080",
        array|string|false $secureInterfaces = false,
        string $webroot = 'public',
        bool $showStackTrace = false,
        bool $showExceptions = false,
        array $pemCertificates = [],
        array $headers = [],
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
            /** @var LoggerInterface */
            $logger = yield self::logger();

            if (self::$started) {
                $logger->error("A web server is already running.");
                die();
            }

            self::$started = true;


            $config = new HttpConfiguration();

            Container::setObject(HttpConfiguration::class, $config);

            $config->pemCertificates = [];

            foreach ($pemCertificates as $domain => $cert) {
                $file                             = $cert['file'] ?? '';
                $key                              = $cert['key']  ?? '';
                $config->pemCertificates[$domain] = new Certificate($file, $key);
            }

            $config->httpInterfaces       = $interfaces;
            $config->httpSecureInterfaces = $secureInterfaces;
            $config->httpWebroot          = $webroot;
            $config->httpShowStackTrace   = $showStackTrace;
            $config->httpShowExceptions   = $showExceptions;
            $config->headers              = $headers;

            yield self::init($config);

            Session::setOperations(
                new FileSystemSessionOperations(
                    ttl      : 1_440,
                    dirname  : ".sessions",
                    keepAlive: false,
                )
            );


            $config->mdp = new Parsedown();

            $invoker = new HttpInvoker(Session::getOperations());

            $sockets = [];

            if (!is_iterable($config->httpInterfaces)) {
                $interfaces = [$config->httpInterfaces];
            } else {
                $interfaces = $config->httpInterfaces;
            }


            foreach ($interfaces as $interface) {
                $sockets[] = Server::listen($interface);
            }

            if ($config->pemCertificates) {
                $tlscontext = (new ServerTlsContext())
                    ->withCertificates($config->pemCertificates)
                ;

                $context = (new BindContext())
                    ->withTlsContext($tlscontext)
                ;

                foreach ($context->getTlsContext()->getCertificates() as $certificate) {
                    $logger->info("Using certificate file {$certificate->getCertFile()}");
                    $logger->info("Using certificate key file {$certificate->getKeyFile()}");
                }


                $logger->info("Using CA path {$context->getTlsContext()->getCaPath()}");
                $logger->info("Using CA file {$context->getTlsContext()->getCaFile()}");


                if (!is_iterable($config->httpSecureInterfaces)) {
                    $secureInterfaces = [$config->httpSecureInterfaces ?? []];
                } else {
                    $secureInterfaces = $config->httpSecureInterfaces;
                }

                foreach ($secureInterfaces as $interface) {
                    if ($interface) {
                        $sockets[] = Server::listen($interface, $context);
                    }
                }
            } elseif ($config->httpSecureInterfaces && count($config->httpSecureInterfaces) > 0) {
                $logger->critical("Server could not bind to the secure network interfaces because no pem certificate has been provided.");
            }

            if (0 >= count($sockets)) {
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

            if (DIRECTORY_SEPARATOR === '/') {
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

    private static function init(HttpConfiguration $config): Promise {
        return Route::notFound(notfound($config));
    }


    /**
     * @throws Exception
     * @throws Throwable
     */
    private static function serve(
        HttpConfiguration $config,
        Request $request,
        HttpInvoker $invoker
    ): Generator {
        $logger        = yield Container::create(LoggerInterface::class);
        $requestMethod = $request->getMethod();
        $requestUri    = $request->getUri();
        $requestPath   = $requestUri->getPath();

        //check if request matches any exposed endpoint and extract parameters
        [$requestPath, $requestPathParameters] = static::usingPath($requestMethod, $requestPath, Route::getAllRoutes());

        if (!$requestPath) {
            $response = yield from $invoker->invoke(
                request              : $request,
                requestMethod        : $requestMethod,
                requestPath          : '@404',
                requestPathParameters: $requestPathParameters,
            );

            if (!$response) {
                $logger->error("There is no event listener or controller that manages \"404 Not Found\" requests, serving an empty \"500 Internal Server Error\" response instead.");
                $response = new Response(Status::INTERNAL_SERVER_ERROR);
            }
            return $response;
        }

        try {
            $response = yield from $invoker->invoke(
                request              : $request,
                requestMethod        : $requestMethod,
                requestPath          : $requestPath,
                requestPathParameters: $requestPathParameters,
            );

            if (!$response) {
                $logger->critical("The path matcher returned a match for \"$requestMethod\" but the invoker couldn't find the function/method to invoke, serving an empty \"500 Internal Server Error\" response instead.");
                $response = new Response(Status::INTERNAL_SERVER_ERROR);
            }
            return $response;
        } catch (Throwable $e) {
            $message = $config->httpShowExceptions ? $e->getMessage() : '';
            $trace   = $config->httpShowExceptions && $config->httpShowStackTrace ? "\n".$e->getTraceAsString() : '';
            $logger->error($e->getMessage());
            $logger->error($e->getTraceAsString());
            return new Response(500, [], $message.$trace);
        }
    }

    private static array $cache = [];

    private static function usingPath(string $requestMethod, string $requestPath, array $callbacks) {
        if (!isset($callbacks[$requestMethod])) {
            return [false, []];
        }

        $finalLocalPath      = false;
        $finalAllParams      = [];
        $countFinalAllParams = -1;

        foreach ($callbacks[$requestMethod] as $localPath => $filters) {
            if (!isset(self::$cache[$requestMethod])) {
                self::$cache[$requestMethod] = [];
            }
            if (isset(self::$cache[$requestMethod][$localPath])) {
                $patternGroups = self::$cache[$requestMethod][$localPath];
            } else {
                $patternGroups = [];
                foreach (Route::findPattern($requestMethod, $localPath) as $g) {
                    $patternGroups[] = $g;
                }
                self::$cache[$requestMethod][$localPath] = $patternGroups;
            }
            $ok        = false;
            $allParams = [];
            /** @var callable $pattern */
            foreach ( $patternGroups as $i => $patterns ) {
                foreach ($patterns as $pattern) {
                    [$k, $params] = $pattern($requestPath);
                    if ($k) {
                        $ok = true;
                        foreach ($params as $key => $value) {
                            $allParams[$i][$key] = $value;
                        }
                    }
                }
            }
            if ($ok) {
                $countParams = count($params);
                if (0 === $countParams) {
                    return [$localPath, $allParams];
                }

                if ($countParams < $countFinalAllParams || -1 === $countFinalAllParams) {
                    $finalLocalPath      = $localPath;
                    $finalAllParams      = $allParams;
                    $countFinalAllParams = $countParams;
                }
            }
        }
        return [$finalLocalPath, $finalAllParams];
    }
}

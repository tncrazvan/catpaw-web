<?php

namespace CatPaw\Web;

use Amp\ByteStream\IteratorStream;
use function Amp\call;
use function Amp\File\exists;
use Amp\File\File;
use function Amp\File\getSize;
use function Amp\File\isDirectory;
use function Amp\File\openFile;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\LazyPromise;
use Amp\Loop;
use Amp\Producer;
use Amp\Promise;
use Amp\Socket\BindContext;
use Amp\Socket\Server;
use Amp\Socket\ServerTlsContext;
use CatPaw\Utilities\Container;
use CatPaw\Utilities\Strings;
use CatPaw\Web\Attributes\RequestHeader;
use CatPaw\Web\Attributes\Session;
use CatPaw\Web\Exceptions\InvalidByteRangeQueryException;
use CatPaw\Web\Interfaces\ByteRangeWriterInterface;
use CatPaw\Web\Services\ByteRangeService;
use CatPaw\Web\Session\FileSystemSessionOperations;
use CatPaw\Web\Utilities\Mime;

use CatPaw\Web\Utilities\Route;
use function count;
use Exception;
use Generator;
use Parsedown;
use Throwable;

class WebServer {
    private static $started = false;



    private static false|HttpServer $httpServer = false;

    private function __construct() {
    }


    /**
     *
     * @param  HttpConfiguration|array $config
     * @return Promise                 <>
     */
    public static function start(
        HttpConfiguration|array $config
    ): Promise {
        return call(function() use ($config) {
            Container::setObject(HttpConfiguration::class, $config);
            if (self::$started) {
                return;
            }


            self::$started = true;

            self::init($config);

            Session::setOperations(
                new FileSystemSessionOperations(
                    ttl      : 1_440,
                    dirname  : ".sessions",
                    keepAlive: false,
                )
            );


            $config->mdp = new Parsedown();


            if (!$config->logger) {
                die(Strings::red("Please specify a logger instance.\n"));
            }

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
                    ->withCertificates($config->pemCertificates);

                $context = (new BindContext())
                    ->withTlsContext($tlscontext);

                foreach ($context->getTlsContext()->getCertificates() as $certificate) {
                    $config->logger->info("Using certificate file {$certificate->getCertFile()}");
                    $config->logger->info("Using certificate key file {$certificate->getKeyFile()}");
                }


                $config->logger->info("Using CA path {$context->getTlsContext()->getCaPath()}");
                $config->logger->info("Using CA file {$context->getTlsContext()->getCaFile()}");


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
                $config->logger->critical("Server could not bind to the secure network interfaces because no pem certificate has been provided.");
            }

            if (0 >= count($sockets)) {
                $config->logger->error("At least one network interface must be provided in order to start the server.");
                die();
            }

            $server = self::$httpServer = new HttpServer(
                $sockets,
                new CallableRequestHandler(
                    static fn(Request $request) => static::serve($config, $request, $invoker)
                ),
                $config->logger
            );

            $server->setErrorHandler(new class() implements \Amp\Http\Server\ErrorHandler {
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

    public static function init(HttpConfiguration $config): void {
        Route::notFound(notfound($config));
    }


    /**
     * @throws Exception
     * @throws Throwable
     */
    private static function serve(HttpConfiguration $config, Request $httpRequest, HttpInvoker $invoker): Generator {
        $httpRequestMethod = $httpRequest->getMethod();
        $httpRequestUri = $httpRequest->getUri();
        $httpRequestPath = $httpRequestUri->getPath();

        //check if request matches any exposed endpoint and extract parameters
        [$httpRequestPath, $httpRequestPathParameters] = yield from static::usingPath($httpRequestMethod, $httpRequestPath, Route::$routes);

        if (!$httpRequestPath) {
            $response = yield from $invoker->invoke(
                httpRequest              : $httpRequest,
                httpRequestMethod        : $httpRequestMethod,
                httpRequestPath          : '@404',
                httpRequestPathParameters: $httpRequestPathParameters,
            );

            if (!$response) {
                $config->logger->error("There is no event listener or controller that manages \"404 Not Found\" requests, serving an empty \"500 Internal Server Error\" response instead.");
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

            if (!$response) {
                $config->logger->critical("The path matcher returned a match for \"$httpRequestMethod\" but the invoker couldn't find the function/method to invoke, serving an empty \"500 Internal Server Error\" response instead.");
                $response = new Response(Status::INTERNAL_SERVER_ERROR);
            }
            return $response;
        } catch (Throwable $e) {
            $message = $config->httpShowExceptions ? $e->getMessage() : '';
            $trace = $config->httpShowExceptions && $config->httpShowStackTrace ? "\n".$e->getTraceAsString() : '';
            $config->logger->error($e->getMessage());
            $config->logger->error($e->getTraceAsString());
            return new Response(500, [], $message.$trace);
        }
    }

    private static array $cache = [];

    private static function usingPath(string $httpRequestMethod, string $httpRequestPath, array $callbacks): Generator {
        if (!isset($callbacks[$httpRequestMethod])) {
            return [false, []];
        }
        foreach ($callbacks[$httpRequestMethod] as $localPath => $callback) {
            if (!isset(self::$cache[$httpRequestMethod])) {
                self::$cache[$httpRequestMethod] = [];
            }
            if (isset(self::$cache[$httpRequestMethod][$localPath])) {
                $patterns = self::$cache[$httpRequestMethod][$localPath];
            } else {
                $patterns = [];
                foreach (Route::$patterns[$httpRequestMethod][$localPath] as $g) {
                    $patterns[] = yield from $g;
                }
                self::$cache[$httpRequestMethod][$localPath] = $patterns;
            }
            $ok = false;
            $allParams = [];
            /** @var callable $pattern */
            foreach ($patterns as $pattern) {
                [$k, $params] = $pattern($httpRequestPath);
                if ($k) {
                    $ok = true;
                    $allParams[] = $params;
                }
            }
            if ($ok) {
                return [$localPath, $allParams];
            }
        }
        return [false, []];
    }
}

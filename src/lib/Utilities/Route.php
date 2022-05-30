<?php

namespace CatPaw\Web\Utilities;

use function Amp\call;
use Amp\LazyPromise;
use Amp\Promise;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Utilities\AsciiTable;
use CatPaw\Utilities\Strings;
use CatPaw\Web\Attributes\Param;
use CatPaw\Web\RouteHandlerContext;
use Closure;

use Generator;
use function implode;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionType;
use ReflectionUnionType;

class Route {
    private static array $routes = [];
    private static array $patterns = [];

    public static function findRoute(string $method, string $path): array {
        return self::$routes[$method][$path];
    }

    public static function getAllRoutes(): array {
        return self::$routes;
    }

    public static function findPattern(string $method, string $path): array {
        return self::$patterns[$method][$path];
    }


    public static function clearAll(): void {
        self::$routes = [];
        self::$patterns = [];
    }


    public static function describe(): string {
        $table = new AsciiTable();
        $table->add("Method", "Path");
        foreach (self::$routes as $method => $paths) {
            foreach ($paths as $path => $callback) {
                if (!str_starts_with($path, '@')) {
                    $table->add($method, $path);
                }
            }
        }
        return $table->toString().PHP_EOL;
    }

	private static $pathPatternsCache = [];

    /**
     * @param  string    $path
     * @param  array     $params
     * @return Generator
     */
    private static function findPathPatterns(
        string $path,
        array $params
    ): Generator {
		if(isset(self::$pathPatternsCache[$path]))
			return self::$pathPatternsCache[$path];

        $targets = [
            'pathParams' => [],
            'names' => [],
            'rawNames' => [],
        ];
        foreach ($params as $param) {
            /** @var Param $pathParam */
            $pathParam = yield Param::findByParameter($param);
            if ($pathParam) {
                $optional = $param->isOptional();
                $typeName = 'string';

                $type = $param->getType();
                if ($type instanceof ReflectionUnionType) {
                    $typeName = $type->getTypes()[0]->getName();
                } elseif ($type instanceof ReflectionType) {
                    $typeName = $type->getName();
                }

                if ('' === $pathParam->getRegex()) {
                    switch ($typeName) {
                        case 'int':
                            $pathParam->setRegex('/^[-+]?[0-9]+$/');
                            break;
                        case 'float':
                            $pathParam->setRegex('/^[-+]?[0-9]+\.[0-9]+$/');
                            break;
                        case 'string':
                            $pathParam->setRegex('/^[^\/]+$/');
                            break;
                        case 'bool':
                            $pathParam->setRegex('/^(0|1|no?|y(es)?|false|true)$/');
                            break;
                    }
                }
                $targets['pathParams'][] = $pathParam;
                $targets['names'][] = '\{'.$param->getName().'\}';
                $targets['rawNames'][] = $param->getName();
            }
        }

        if (count($targets['names']) > 0) {
            $localPieces = preg_split('/('.join("|", $targets['names']).')/', $path);
            $pattern = '/(?<={)('.join('|', $targets['rawNames']).')(?=})/';
            $matches = [];
            preg_match_all($pattern, $path, $matches);
            [$names] = $matches;
            $orderedTargets = [
                'pathParams' => [],
                'names' => [],
                'rawNames' => [],
            ];
            $len = count($targets['rawNames']);
            foreach ($names as $name) {
                for ($i = 0; $i < $len; $i++) {
                    if ($targets['rawNames'][$i] === $name) {
                        $orderedTargets['pathParams'][] = $targets['pathParams'][$i];
                        $orderedTargets['names'][] = $targets['names'][$i];
                        $orderedTargets['rawNames'][] = $targets['rawNames'][$i];
                    }
                }
            }
            $targets = $orderedTargets;
        } else {
            $localPieces = [$path];
        }

        $piecesLen = count($localPieces);

        $resolver = function(string $requestedPath) use ($targets, $localPieces, $piecesLen, $path) {
            $variables = [];
            $offset = 0;
            $reconstructed = '';
            $pathParams = $targets['pathParams'];
            for ($i = 0; $i < $piecesLen; $i++) {
                $piece = $localPieces[$i];
                $plen = strlen($piece);
                if ($piece === ($subrp = substr($requestedPath, $offset, $plen))) {
                    $offset += strlen($subrp);
                    $reconstructed .= $subrp;
                    if (isset($pathParams[$i])) {
                        /** @var Param $param */
                        $param = $pathParams[$i];
                        $next = $localPieces[$i + 1] ?? false;
                        if (false !== $next) {
                            $end = '' === $next ? strlen($requestedPath) : strpos($requestedPath, $next, $offset);

                            if ($end === $offset) {
                                return [false, []];
                            }
                            $variable = substr($requestedPath, $offset, ($len = $end - $offset));
                            if (!preg_match($param->getRegex(), $variable)) {
                                return [false, []];
                            }
                            $offset += $len;
                            $reconstructed .= $variable;
                            $variables[$targets['rawNames'][$i]] = urldecode($variable);
                        }
                    }
                }
            }
            $ok = $reconstructed === $requestedPath;
            return [$ok, $variables];
        };

		self::$pathPatternsCache[$path] = $resolver;

		return $resolver;
    }

    /**
     * @param  string        $method
     * @param  string        $path
     * @param  array|Closure $callbacks
     * @return Promise
     */
    private static function initialize(
        string $method,
        string $path,
        array|Closure $callbacks,
    ): Promise {
        return call(function() use (
            $method,
            $path,
            $callbacks,
        ) {
            if (self::$routes[$method][$path] ?? false) {
                if (!str_starts_with($path, "@")) {
                    die(Strings::red("Overwriting handler [ $method $path ]\n"));
                } else {
                    echo(Strings::yellow("Overwriting handler [ $method $path ]\n"));
                    self::$routes[$method][$path] = [];
                }
            }
    
            if (!is_array($callbacks)) {
                $callbacks = [$callbacks];
            }
    
    
            try {
                $len = count($callbacks);
                foreach ($callbacks as $i => $callback) {
                    $isFilter = $len > 1 && $i < $len - 1;
                    $reflection = new ReflectionFunction($callback);
                    self::$patterns[$method][$path][] = self::findPathPatterns($path, $reflection->getParameters());
                    self::$routes[$method][$path][] = $callback;
                    //TODO refactor this attributes section
                    $context = new class(method: $method, path: $path, isFilter: $isFilter, ) extends RouteHandlerContext {
                        public function __construct(
                            public string $method,
                            public string $path,
                            public bool $isFilter,
                        ) {
                        }
                    };
    
                    foreach ($reflection->getAttributes() as $attribute) {
                        $aname = $attribute->getName();
                        /** @var AttributeInterface $ainstance */
                        $ainstance = yield $aname::findByFunction($reflection);
                        if ($ainstance) {
                            yield $ainstance->onRouteHandler($reflection, $callback, $context);
                        }
                    }
                }
            } catch (ReflectionException $e) {
                die(Strings::red($e));
            }
        });
    }

    /**
     * @param ReflectionMethod $reflection_method
     *
     * @return array
     */
    public static function getMappedParameters(ReflectionMethod $reflection_method): array {
        $reflectionParameters = $reflection_method->getParameters();
        $namedAndTypedParams = [];
        $namedParams = [];
        foreach ($reflectionParameters as $reflectionParameter) {
            $name = $reflectionParameter->getName();
            $type = $reflectionParameter->getType()->getName();
            $namedAndTypedParams[] = "$type &\$$name";
            $namedParams[] = "\$$name";
        }
        $namedAndTypedParamsString = implode(',', $namedAndTypedParams);
        $namedParamsString = implode(',', $namedParams);
        return [$namedAndTypedParamsString, $namedParamsString];
    }

    /**
     * Define an alias for an already existing web server path name.
     *
     * @param string $method   http method of the 2 params
     * @param string $original path name to capture
     * @param string $alias    alias path name
     */
    public static function alias(string $method, string $original, string $alias): void {
        if (isset(self::$routes[$method][$original])) {
            self::custom($method, $alias, self::$routes[$method][$original]);
        } else {
            die(Strings::red("Trying to create alias \"$alias\" => \"$original\", but the original route \"$original\" has not beed defined.\n"));
        }
    }

    /**
     * Define a callback to run when a resource is not found.
     *
     * @param array|Closure $callback
     *
     * @return void
     */
    public static function notFound(array|Closure $callback): Promise {
        return call(function() use ($callback) {
            yield static::copy('@404', $callback);
            yield static::delete('@404', $callback);
            yield static::get('@404', $callback);
            yield static::head('@404', $callback);
            yield static::link('@404', $callback);
            yield static::lock('@404', $callback);
            yield static::options('@404', $callback);
            yield static::patch('@404', $callback);
            yield static::post('@404', $callback);
            yield static::propfind('@404', $callback);
            yield static::purge('@404', $callback);
            yield static::put('@404', $callback);
            yield static::unknown('@404', $callback);
            yield static::unlink('@404', $callback);
            yield static::unlock('@404', $callback);
            return yield static::view('@404', $callback);
        });
    }


    /**
     * Define an event callback for a custom http method.
     *
     * @param string        $method   the name of the http method.
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function custom(string $method, string $path, array|Closure $callback): Promise {
        return static::initialize($method, $path, $callback);
    }

    /**
     * Define an event callback for the "COPY" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function copy(string $path, array|Closure $callback): Promise {
        return static::initialize('COPY', $path, $callback);
    }

    /**
     * Define an event callback for the "COPY" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function delete(string $path, array|Closure $callback): Promise {
        return static::initialize('DELETE', $path, $callback);
    }

    /**
     * Define an event callback for the "COPY" http method.
     *
     * @param  string        $path     the path the event should listen to.
     * @param  array|Closure $callback the callback to execute.
     * @return void
     */
    public static function get(string $path, array|Closure $callback): Promise {
        return static::initialize('GET', $path, $callback);
    }

    /**
     * Define an event callback for the "HEAD" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function head(string $path, array|Closure $callback): Promise {
        return static::initialize('HEAD', $path, $callback);
    }

    /**
     * Define an event callback for the "LINK" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function link(string $path, array|Closure $callback): Promise {
        return static::initialize('LINK', $path, $callback);
    }

    /**
     * Define an event callback for the "LOCK" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function lock(string $path, array|Closure $callback): Promise {
        return static::initialize('LOCK', $path, $callback);
    }

    /**
     * Define an event callback for the "OPTIONS" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function options(string $path, array|Closure $callback): Promise {
        return static::initialize('OPTIONS', $path, $callback);
    }

    /**
     * Define an event callback for the "PATCH" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function patch(string $path, array|Closure $callback): Promise {
        return static::initialize('PATCH', $path, $callback);
    }

    /**
     * Define an event callback for the "POST" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function post(string $path, array|Closure $callback): Promise {
        return static::initialize('POST', $path, $callback);
    }

    /**
     * Define an event callback for the "PROPFIND" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function propfind(string $path, array|Closure $callback): Promise {
        return static::initialize('PROPFIND', $path, $callback);
    }

    /**
     * Define an event callback for the "PURGE" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function purge(string $path, array|Closure $callback): Promise {
        return static::initialize('PURGE', $path, $callback);
    }

    /**
     * Define an event callback for the "PUT" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function put(string $path, array|Closure $callback): Promise {
        return static::initialize('PUT', $path, $callback);
    }

    /**
     * Define an event callback for the "UNKNOWN" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function unknown(string $path, array|Closure $callback): Promise {
        return static::initialize('UNKNOWN', $path, $callback);
    }

    /**
     * Define an event callback for the "UNLINK" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function unlink(string $path, array|Closure $callback): Promise {
        return static::initialize('UNLINK', $path, $callback);
    }

    /**
     * Define an event callback for the "UNLOCK" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function unlock(string $path, array|Closure $callback): Promise {
        return static::initialize('UNLOCK', $path, $callback);
    }

    /**
     * Define an event callback for the "VIEW" http method.
     *
     * @param string        $path     the path the event should listen to.
     * @param array|Closure $callback the callback to execute.
     */
    public static function view(string $path, array|Closure $callback): Promise {
        return static::initialize('VIEW', $path, $callback);
    }
}

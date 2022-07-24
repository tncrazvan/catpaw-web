<?php

namespace CatPaw\Web\Utilities;

use function Amp\call;
use Amp\Promise;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Utilities\AsciiTable;
use CatPaw\Utilities\Container;
use CatPaw\Utilities\ReflectionTypeManager;
use CatPaw\Utilities\Strings;
use CatPaw\Web\Attributes\Consumes;
use CatPaw\Web\Attributes\Example;
use CatPaw\Web\Attributes\Param;
use CatPaw\Web\Attributes\PathParam;
use CatPaw\Web\Attributes\Produces;
use CatPaw\Web\Attributes\RequestBody;
use CatPaw\Web\Attributes\RequestHeader;
use CatPaw\Web\Attributes\RequestQuery;
use CatPaw\Web\Attributes\Summary;
use CatPaw\Web\RouteHandlerContext;
use CatPaw\Web\Services\OpenAPIService;
use Closure;

use Generator;
use function implode;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

class Route {
    private static array $routes      = [];
    private static array $reflections = [];
    private static array $consumes    = [];
    private static array $produces    = [];
    private static array $patterns    = [];

    /**
     * Find a route.
     * @param  string      $method
     * @param  string      $path
     * @return false|array
     */
    public static function findRoute(
        string $method,
        string $path,
    ): false|array {
        return self::$routes[$method][$path] ?? false;
    }

    /**
     * Find the ReflectionFunction of a given route.
     * @param  string                   $method
     * @param  string                   $path
     * @param  int                      $index  the index of the filter or 0 if the route has no filters.
     * @return false|ReflectionFunction
     */
    public static function findReflection(
        string $method,
        string $path,
        int $index,
    ): false|ReflectionFunction {
        return self::$reflections[$method][$path][$index];
    }

    /**
     * Find the produced content-type of a route.
     * @param  string         $method
     * @param  string         $path
     * @param  int            $index  the index of the filter or 0 if the route has no filters.
     * @return false|Produces
     */
    public static function findProduces(
        string $method,
        string $path,
        int $index,
    ): false|Produces {
        return self::$produces[$method][$path][$index] ?? false;
    }

    /**
     * Find the consumed content-type of a route.
     * @param  string         $method
     * @param  string         $path
     * @param  int            $index  the index of the filter or 0 if the route has no filters.
     * @return false|Consumes
     */
    public static function findConsumes(
        string $method,
        string $path,
        int $index,
    ): false|Consumes {
        return self::$consumes[$method][$path][$index] ?? false;
    }

    /**
     * Find a list of consumed produced-types for each filter of a route.
     * @param  string $method
     * @param  string $path
     * @return array
     */
    public static function findIndexedProduces(
        string $method,
        string $path,
    ): array {
        return self::$produces[$method][$path] ?? false;
    }

    /**
     * Find a list of consumed content-types for each filter of a route.
     * @param  string $method
     * @param  string $path
     * @return array
     */
    public static function findIndexedConsumes(
        string $method,
        string $path,
    ): array {
        return self::$consumes[$method][$path] ?? false;
    }

    /**
     * Find the produced content-type of a route.
     * @param  string         $method
     * @param  string         $path
     * @param  int            $index    the index of the filter or 0 if the route has no filters.
     * @param  false|Produces $produces
     * @return void
     */
    public static function setProduces(
        string $method,
        string $path,
        int $index,
        false|Produces $produces
    ): void {
        self::$produces[$method][$path][$index] = $produces;
    }

    /**
     * Set the consumed content-type of a route.
     * @param  string         $method
     * @param  string         $path
     * @param  int            $index    the index of the filter or 0 if the route has no filters.
     * @param  false|Consumes $consumes
     * @return void
     */
    public static function setConsumes(
        string $method,
        string $path,
        int $index,
        false|Consumes $consumes
    ): void {
        self::$consumes[$method][$path][$index] = $consumes;
    }

    /**
     * Get all routes.
     * @return array
     */
    public static function getAllRoutes(): array {
        return self::$routes;
    }

    /**
     * Find path pattern.<br/>
     * 
     * @param  string $method
     * @param  string $path
     * @return array  a list of functions that can be dynamically invoked.
     *                       Each function has the following signature <b>function(string $requestedPath):[$ok,$variables]</b>.<br/>
     *                       If the given combination of <b>$method</b> and <b>$path</b> has not been registered beforehand using <b>Route::{httpMethod}</b> (or using a controller), this method will return an empty array.
     */
    public static function findPattern(string $method, string $path): array {
        return self::$patterns[$method][$path] ?? [];
    }

    /**
     * Cleares all routes and other metadata 
     * including patterns and consumed/produced content-types.
     * @return void
     */
    public static function clearAll(): void {
        self::$routes   = [];
        self::$patterns = [];
        self::$consumes = [];
        self::$produces = [];
    }


    /**
     * Returns an ASCI table describing all existing routes.
     * @return string
     */
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
        array $params,
        int $index,
    ): Generator {
        if (isset(self::$pathPatternsCache[$path][$index])) {
            return self::$pathPatternsCache[$path][$index];
        }

        $targets = [
            'pathParams' => [],
            'names'      => [],
            'rawNames'   => [],
        ];
        foreach ($params as $param) {
            /** @var Param|null $pathParam */
            $pathParam = yield Param::findByParameter($param);
            if ($pathParam) {
                $typeName = 'string';

                $type = $param->getType();
                if ($type instanceof ReflectionUnionType) {
                    $typeName = $type->getTypes()[0]->getName();
                } elseif ($type instanceof ReflectionNamedType) {
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
                $targets['names'][]      = '\{'.$param->getName().'\}';
                $targets['rawNames'][]   = $param->getName();
            }
        }

        if (count($targets['names']) > 0) {
            $localPieces = preg_split('/('.join("|", $targets['names']).')/', $path);
            $pattern     = '/(?<={)('.join('|', $targets['rawNames']).')(?=})/';
            $matches     = [];
            preg_match_all($pattern, $path, $matches);
            [$names]        = $matches;
            $orderedTargets = [
                'pathParams' => [],
                'names'      => [],
                'rawNames'   => [],
            ];
            $len = count($targets['rawNames']);
            foreach ($names as $name) {
                for ($i = 0; $i < $len; $i++) {
                    if ($targets['rawNames'][$i] === $name) {
                        $orderedTargets['pathParams'][] = $targets['pathParams'][$i];
                        $orderedTargets['names'][]      = $targets['names'][$i];
                        $orderedTargets['rawNames'][]   = $targets['rawNames'][$i];
                    }
                }
            }
            $targets = $orderedTargets;
        } else {
            $localPieces = [$path];
        }

        $piecesLen = count($localPieces);

        $resolver          = function(string $requestedPath) use ($targets, $localPieces, $piecesLen) {
            $variables     = [];
            $offset        = 0;
            $reconstructed = '';
            $pathParams    = $targets['pathParams'];
            for ($i = 0; $i < $piecesLen; $i++) {
                $piece = $localPieces[$i];
                $plen  = strlen($piece);
                if ($piece === ($subrp = substr($requestedPath, $offset, $plen))) {
                    $offset += strlen($subrp);
                    $reconstructed .= $subrp;
                    if (isset($pathParams[$i])) {
                        /** @var Param $param */
                        $param = $pathParams[$i];
                        $next  = $localPieces[$i + 1] ?? false;
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

        self::$pathPatternsCache[$path][$index] = $resolver;

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
                    $isFilter   = $len > 1 && $i < $len - 1;
                    $reflection = new ReflectionFunction($callback);
                    
                    self::$reflections[$method][$path][$i] = $reflection;

                    self::$consumes[$method][$path][$i] = yield Consumes::findByFunction($reflection);
                    self::$produces[$method][$path][$i] = yield Produces::findByFunction($reflection);

                    $parameters = $reflection->getParameters();

                    self::$patterns[$method][$path][$i][] = yield from self::findPathPatterns($path, $parameters, $i);
                    self::$routes[$method][$path][$i]     = $callback;
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

                    if ($isFilter || \str_starts_with($path, '@')) {
                        continue;
                    }

                    yield from self::registerClosureForOpenAPI($reflection, $path, $method, $parameters);
                }
            } catch (ReflectionException $e) {
                die(
                    Strings::red(
                        contents: \join(
                            separator: "\n",
                            array: [
                                $e->getMessage(),
                                $e->getTraceAsString(),
                            ],
                        ),
                    )
                );
            }
        });
    }

    /**
     * Undocumented function
     *
     * @param  array<ReflectionParameter> $parameters
     * @return Generator
     */
    private static function registerClosureForOpenAPI(
        ReflectionFunction $reflection,
        string $path,
        string $method,
        array $parameters
    ) {
        $unwrappedResponses = [];
        
        /** @var Produces|null */
        $produces = yield Produces::findByFunction($reflection);
        if ($produces) {
            $producedResponses = $produces->getProducedResponses();
            foreach ($producedResponses as $response) {
                $unwrappedResponse = $response->getValue();
                foreach ($unwrappedResponse as $status => $content) {
                    $unwrappedResponses[$status] = $content;
                }
            }
        }


        $apiParameters = [];
        $responses     = $produces?[...$unwrappedResponses]:[];


        /** @var OpenAPIService */
        $api = yield Container::create(OpenAPIService::class);

        foreach ($parameters as $parameter) {
            $type = ReflectionTypeManager::unwrap($parameter);
            if (!$type) {
                continue;
            }

            /** @var Summary|null */
            $summary = (yield Summary::findByParameter($parameter));

            /** @var array{type:string} */
            $schema = ["type" => ReflectionTypeManager::unwrap($parameter)->getName()];
            
            /** @var Example|null */
            $example = (yield Example::findByParameter($parameter));

            /** @var PathParam|null */
            $pathParam = yield PathParam::findByParameter($parameter);

            /** @var Param|null */
            $param = yield Param::findByParameter($parameter);

            /** @var RequestQuery|null */
            $query = yield RequestQuery::findByParameter($parameter);
            
            /** @var RequestHeader|null */
            $header = yield RequestHeader::findByParameter($parameter);

            /** @var RequestBody|null */
            $body = yield RequestBody::findByParameter($parameter); //todo: expose body
                
            if ($query) {
                $key = $query->getName();
                if ('' === $key) {
                    $key = $parameter->getName();
                }

                $apiParameters = [
                    ...$apiParameters,
                    ...$api->createParameter(
                        name: $key,
                        in: 'query',
                        description: $summary?$summary->getValue():(new Summary(value:''))->getValue(),
                        required: false,
                        schema: $schema,
                        examples: $example?$example->getValue():[],
                    ),
                ];
            }
            
            if ($param || $pathParam) {
                $apiParameters = [
                    ...$apiParameters,
                    ...$api->createParameter(
                        name: $parameter->getName(),
                        in: 'path',
                        description: $summary?$summary->getValue():(new Summary(value:''))->getValue(),
                        required: true,
                        schema: $schema,
                        examples: $example?$example->getValue():[],
                    ),
                ];
            }

            if ($header) {
                $apiParameters = [
                    ...$apiParameters,
                    ...$api->createParameter(
                        name: $header->getKey(),
                        in: 'header',
                        description: $summary?$summary->getValue():(new Summary(value:''))->getValue(),
                        required: false,
                        schema: $schema,
                        examples: $example?$example->getValue():[],
                    ),
                ];
            }
        }

        /** @var Summary */
        $summary = (yield Summary::findByFunction($reflection)) ?? new Summary('');

        $api->setPath(
            path: $path,
            pathContent: [
                ...$api->createPathContent(
                    method: $method,
                    operationID: \sha1("$method:$path:".\sha1(\json_encode($apiParameters))),
                    summary: $summary->getValue(),
                    parameters: $apiParameters,
                    responses: $responses,
                ),
            ],
        );
    }

    /**
     * @param ReflectionMethod $reflection_method
     *
     * @return array
     */
    public static function getMappedParameters(ReflectionMethod $reflection_method): array {
        $reflectionParameters = $reflection_method->getParameters();
        $namedAndTypedParams  = [];
        $namedParams          = [];
        foreach ($reflectionParameters as $reflectionParameter) {
            $name                  = $reflectionParameter->getName();
            $type                  = $reflectionParameter->getType()->getName();
            $namedAndTypedParams[] = "$type &\$$name";
            $namedParams[]         = "\$$name";
        }
        $namedAndTypedParamsString = implode(',', $namedAndTypedParams);
        $namedParamsString         = implode(',', $namedParams);
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
     * @return Promise<void>
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
            yield static::view('@404', $callback);
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

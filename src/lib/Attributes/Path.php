<?php
namespace CatPaw\Web\Attributes;

use function Amp\call;
use Amp\Promise;
use Attribute;
use CatPaw\Attributes\Entry;
use CatPaw\Attributes\Service;
use CatPaw\Attributes\Singleton;
use CatPaw\Utilities\Container;
use CatPaw\Web\Utilities\Route;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

#[Attribute]
class Path extends Service {
    /**
     * Maps a class as a controller or extends a method's path.
     * @param string $value path value.
     */
    public function __construct(private string $value = '') {
    }

    private LoggerInterface $logger;
    #[Entry]
    public function setup(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function getValue():string {
        return $this->value;
    }

    public function onClassInstantiation(
        ReflectionClass $reflection,
        mixed &$instance,
        mixed $context
    ): Promise {
        //TODO: refactor this whole method into smaller parts
        return call(function() use ($reflection, $instance) {
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            if (0 === count($methods)) {
                return;
            }

            $filters = yield Filters::findByClass($reflection);

            $GET      = yield GET::findByClass($reflection);
            $DELETE   = yield DELETE::findByClass($reflection);
            $POST     = yield POST::findByClass($reflection);
            $PUT      = yield PUT::findByClass($reflection);
            $COPY     = yield COPY::findByClass($reflection);
            $HEAD     = yield HEAD::findByClass($reflection);
            $LINK     = yield LINK::findByClass($reflection);
            $LOCK     = yield LOCK::findByClass($reflection);
            $OPTIONS  = yield OPTIONS::findByClass($reflection);
            $PATCH    = yield PATCH::findByClass($reflection);
            $PROPFIND = yield PROPFIND::findByClass($reflection);
            $PURGE    = yield PURGE::findByClass($reflection);
            $UNKNOWN  = yield UNKNOWN::findByClass($reflection);
            $UNLINK   = yield UNLINK::findByClass($reflection);
            $UNLOCK   = yield UNLOCK::findByClass($reflection);

            /** @var Produces $producesOfClass */
            $producesOfClass = yield Produces::findByClass($reflection);
            /** @var Consumes $consumesOfClass */
            $consumesOfClass = yield Consumes::findByClass($reflection);

            $classPathValue = $this->value;

            if (!str_starts_with($classPathValue, '/')) {
                $classPathValue = '/'.$classPathValue;
            }

            foreach ($methods as $method) {
                /** @var false|self $path */
                if (!($pathAttribute = yield Path::findByMethod($method))) {
                    $pathValue = preg_replace('/\/+/', '/', $classPathValue);
                } else {
                    $pathValue = preg_replace('/\/+/', '/', $classPathValue.$pathAttribute->getValue());
                }


                $GET_OF_METHOD      = ((yield GET::findByMethod($method))     ?? $GET);
                $DELETE_OF_METHOD   = (yield DELETE::findByMethod($method))   ?? $DELETE;
                $POST_OF_METHOD     = (yield POST::findByMethod($method))     ?? $POST;
                $PUT_OF_METHOD      = (yield PUT::findByMethod($method))      ?? $PUT;
                $COPY_OF_METHOD     = (yield COPY::findByMethod($method))     ?? $COPY;
                $HEAD_OF_METHOD     = (yield HEAD::findByMethod($method))     ?? $HEAD;
                $LINK_OF_METHOD     = (yield LINK::findByMethod($method))     ?? $LINK;
                $LOCK_OF_METHOD     = (yield LOCK::findByMethod($method))     ?? $LOCK;
                $OPTIONS_OF_METHOD  = (yield OPTIONS::findByMethod($method))  ?? $OPTIONS;
                $PATCH_OF_METHOD    = (yield PATCH::findByMethod($method))    ?? $PATCH;
                $PROPFIND_OF_METHOD = (yield PROPFIND::findByMethod($method)) ?? $PROPFIND;
                $PURGE_OF_METHOD    = (yield PURGE::findByMethod($method))    ?? $PURGE;
                $UNKNOWN_OF_METHOD  = (yield UNKNOWN::findByMethod($method))  ?? $UNKNOWN;
                $UNLINK_OF_METHOD   = (yield UNLINK::findByMethod($method))   ?? $UNLINK;
                $UNLOCK_OF_METHOD   = (yield UNLOCK::findByMethod($method))   ?? $UNLOCK;

                /** @var Filters $filtersOfMethod */
                $filtersOfMethod = (yield Filters::findByMethod($method)) ?? $filters;
                /** @var Produces $producesOfMethod */
                $producesOfMethod = yield Produces::findByMethod($method);
                /** @var Consumes $consumesOfMethod */
                $consumesOfMethod = yield Consumes::findByMethod($method);


                $inheritsProduces = !(bool)$producesOfMethod && (bool)$producesOfClass;
                $inheritsConsumes = !(bool)$consumesOfMethod && (bool)$consumesOfClass;
                
                $exposed = (bool)$GET_OF_METHOD
                || (bool)$DELETE_OF_METHOD
                || (bool)$POST_OF_METHOD
                || (bool)$PUT_OF_METHOD
                || (bool)$COPY_OF_METHOD
                || (bool)$HEAD_OF_METHOD
                || (bool)$LINK_OF_METHOD
                || (bool)$LOCK_OF_METHOD
                || (bool)$OPTIONS_OF_METHOD
                || (bool)$PATCH_OF_METHOD
                || (bool)$PROPFIND_OF_METHOD
                || (bool)$PURGE_OF_METHOD
                || (bool)$UNKNOWN_OF_METHOD
                || (bool)$UNLINK_OF_METHOD
                || (bool)$UNLOCK_OF_METHOD;

                $closureFilters = [];
                if ($filtersOfMethod) {
                    foreach ($filtersOfMethod->getClassNames() as $className) {
                        $reflectionFilterClass = new ReflectionClass($className);
                        if (!yield Service::findByClass($reflectionFilterClass)) {
                            $this->logger->warning("Class '{$reflectionFilterClass->getName()}' is not a valid filter wrapper because it is not annotated with '".Service::class."' or '".Singleton::class."', therefore it is being skipped.");
                            continue;
                        }

                        $hasEntry = false;
                        foreach ($reflectionFilterClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionFilterMethod) {
                            if (!yield Filter::findByMethod($reflectionFilterMethod)) {
                                continue;
                            }
                            $closureFilters[] = $reflectionFilterMethod->getClosure(yield Container::create($className));
                            $hasEntry         = true;
                        }

                        if (!$hasEntry) {
                            $this->logger->warning("Class '{$reflectionFilterClass->getName()}' is a valid filter wrapper, but it does not contain any method annotated with '".Filter::class."', therefore it is being skipped.");
                        }
                    }
                }

                $closure = [...$closureFilters, $method->getClosure($instance)];

                $methodValue = 'GET';

                if ($GET_OF_METHOD) {
                    yield Route::get($pathValue, $closure);
                    $methodValue = 'GET';
                }
                if ($DELETE_OF_METHOD) {
                    yield Route::delete($pathValue, $closure);
                    $methodValue = 'DELETE';
                }
                if ($POST_OF_METHOD) {
                    yield Route::post($pathValue, $closure);
                    $methodValue = 'POST';
                }
                if ($PUT_OF_METHOD) {
                    yield Route::put($pathValue, $closure);
                    $methodValue = 'PUT';
                }
                if ($COPY_OF_METHOD) {
                    yield Route::copy($pathValue, $closure);
                    $methodValue = 'COPY';
                }
                if ($HEAD_OF_METHOD) {
                    yield Route::head($pathValue, $closure);
                    $methodValue = 'HEAD';
                }
                if ($LINK_OF_METHOD) {
                    yield Route::link($pathValue, $closure);
                    $methodValue = 'LINK';
                }
                if ($LOCK_OF_METHOD) {
                    yield Route::lock($pathValue, $closure);
                    $methodValue = 'LOCK';
                }
                if ($OPTIONS_OF_METHOD) {
                    yield Route::options($pathValue, $closure);
                    $methodValue = 'OPTIONS';
                }
                if ($PATCH_OF_METHOD) {
                    yield Route::patch($pathValue, $closure);
                    $methodValue = 'PATCH';
                }
                if ($PROPFIND_OF_METHOD) {
                    yield Route::propfind($pathValue, $closure);
                    $methodValue = 'PROPFIND';
                }
                if ($PURGE_OF_METHOD) {
                    yield Route::purge($pathValue, $closure);
                    $methodValue = 'PURGE';
                }
                if ($UNKNOWN_OF_METHOD) {
                    yield Route::unknown($pathValue, $closure);
                    $methodValue = 'UNKNOWN';
                }
                if ($UNLINK_OF_METHOD) {
                    yield Route::unlink($pathValue, $closure);
                    $methodValue = 'UNLINK';
                }
                if ($UNLOCK_OF_METHOD) {
                    yield Route::unlock($pathValue, $closure);
                    $methodValue = 'UNLOCK';
                }

                if (!$exposed && ($consumesOfClass || $producesOfClass || $filtersOfMethod)) {
                    yield Route::get($pathValue, $closure);
                    $exposed = true;
                }

                if ($exposed) {
                    if ($inheritsConsumes) {
                        $indexi    = Route::findIndexedConsumes($methodValue, $pathValue);
                        $lastIndex = count($indexi) - 1;

                        Route::setConsumes($methodValue, $pathValue, $lastIndex, $consumesOfClass);
                    }

                    if ($inheritsProduces) {
                        $indexi    = Route::findIndexedProduces($methodValue, $pathValue);
                        $lastIndex = count($indexi) - 1;

                        Route::setProduces($methodValue, $pathValue, $lastIndex, $producesOfClass);
                    }
                }
            }
        });
    }
}
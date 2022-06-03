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

            $filtersOfClass = yield Filters::findByClass($reflection);

            $CLASS_METHODS = [
                'GET'      => yield GET::findByClass($reflection),
                'DELETE'   => yield DELETE::findByClass($reflection),
                'POST'     => yield POST::findByClass($reflection),
                'PUT'      => yield PUT::findByClass($reflection),
                'COPY'     => yield COPY::findByClass($reflection),
                'HEAD'     => yield HEAD::findByClass($reflection),
                'LINK'     => yield LINK::findByClass($reflection),
                'LOCK'     => yield LOCK::findByClass($reflection),
                'OPTIONS'  => yield OPTIONS::findByClass($reflection),
                'PATCH'    => yield PATCH::findByClass($reflection),
                'PROPFIND' => yield PROPFIND::findByClass($reflection),
                'PURGE'    => yield PURGE::findByClass($reflection),
                'UNKNOWN'  => yield UNKNOWN::findByClass($reflection),
                'UNLINK'   => yield UNLINK::findByClass($reflection),
                'UNLOCK'   => yield UNLOCK::findByClass($reflection),
            ];

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

                $METHOD_METHODS = [
                    'GET'      => yield GET::findByMethod($method),
                    'DELETE'   => yield DELETE::findByMethod($method),
                    'POST'     => yield POST::findByMethod($method),
                    'PUT'      => yield PUT::findByMethod($method),
                    'COPY'     => yield COPY::findByMethod($method),
                    'HEAD'     => yield HEAD::findByMethod($method),
                    'LINK'     => yield LINK::findByMethod($method),
                    'LOCK'     => yield LOCK::findByMethod($method),
                    'OPTIONS'  => yield OPTIONS::findByMethod($method),
                    'PATCH'    => yield PATCH::findByMethod($method),
                    'PROPFIND' => yield PROPFIND::findByMethod($method),
                    'PURGE'    => yield PURGE::findByMethod($method),
                    'UNKNOWN'  => yield UNKNOWN::findByMethod($method),
                    'UNLINK'   => yield UNLINK::findByMethod($method),
                    'UNLOCK'   => yield UNLOCK::findByMethod($method),
                ];

                /** @var Filters $filtersOfMethod */
                $filtersOfMethod = yield Filters::findByMethod($method);
                /** @var Produces $producesOfMethod */
                $producesOfMethod = yield Produces::findByMethod($method);
                /** @var Consumes $consumesOfMethod */
                $consumesOfMethod = yield Consumes::findByMethod($method);

                $closureFilters = [];
                if ($filtersOfMethod || $filtersOfClass) {
                    foreach (($filtersOfMethod ?? $filtersOfClass)->getClassNames() as $className) {
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


                $inheritsProduces = !(bool)$producesOfMethod && (bool)$producesOfClass;
                $inheritsConsumes = !(bool)$consumesOfMethod && (bool)$consumesOfClass;
                
                
                $closure = [...$closureFilters, $method->getClosure($instance)];

                $methodValue = '';
                $exposed     = false;
                foreach ($METHOD_METHODS as $key => $value) {
                    if ($value) {
                        yield Route::custom($key, $pathValue, $closure);
                        $exposed = true;
                    } else {
                        if ($CLASS_METHODS[$key] && ($producesOfMethod || $consumesOfMethod || $filtersOfMethod)) {
                            yield Route::custom($key, $pathValue, $closure);
                            $exposed = true;
                        }
                    }
                }

                if (!$exposed && ($producesOfMethod || $consumesOfMethod || $filtersOfMethod)) {
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
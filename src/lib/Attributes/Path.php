<?php
namespace CatPaw\Web\Attributes;

use function Amp\call;
use Amp\Promise;
use Attribute;
use CatPaw\Attributes\Service;
use CatPaw\Web\Utilities\Route;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

#[Attribute]
class Path extends Service {
    /**
     * Maps a class as a controller.
     * @param string $value path value.<br/>
     *                      ust start with either "/" or "@".
     */
    public function __construct(private string $value = '/') {
        if (!str_starts_with($value, '/')) {
            throw new InvalidArgumentException("A web path must start with '/', received '$value'.");
        }
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

            foreach ($methods as $method) {
                /** @var false|self $path */
                if (!($pathAttribute = yield Path::findByMethod($method))) {
                    $pathValue = preg_replace('/\/+/', '/', $this->value);
                } else {
                    $pathValue = preg_replace('/\/+/', '/', $this->value.$pathAttribute->getValue());
                }

                $GET_OF_METHOD      = ((yield GET::findByMethod($method))     ?? $GET)     ?? new GET();
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

                $closure = $method->getClosure($instance);           

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

                if (!$exposed && ($consumesOfClass || $producesOfClass)) {
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
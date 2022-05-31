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
    public function __construct(
        private string $value = '/'
    ) {
        if (!str_starts_with($value, '/')) {
            throw new InvalidArgumentException("A web path must start with '/', received '$value'.");
        }
    }

    public function getValue():string {
        return $this->value;
    }

    public function onClassInstantiation(ReflectionClass $reflection, mixed &$instance, mixed $context): Promise {
        return call(function() use ($reflection, $instance, $context) {
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            if (0 === count($methods)) {
                return;
            }

            $GET = yield GET::findByClass($reflection);
            $DELETE = yield DELETE::findByClass($reflection);
            $POST = yield POST::findByClass($reflection);
            $PUT = yield PUT::findByClass($reflection);
            $COPY = yield COPY::findByClass($reflection);
            $HEAD = yield HEAD::findByClass($reflection);
            $LINK = yield LINK::findByClass($reflection);
            $LOCK = yield LOCK::findByClass($reflection);
            $OPTIONS = yield OPTIONS::findByClass($reflection);
            $PATCH = yield PATCH::findByClass($reflection);
            $PROPFIND = yield PROPFIND::findByClass($reflection);
            $PURGE = yield PURGE::findByClass($reflection);
            $UNKNOWN = yield UNKNOWN::findByClass($reflection);
            $UNLINK = yield UNLINK::findByClass($reflection);
            $UNLOCK = yield UNLOCK::findByClass($reflection);

            foreach ($methods as $method) {
                /** @var false|self $path */
                if (!($pathAttribute = yield Path::findByMethod($method))) {
                    $pathValue = preg_replace('/\/+/', '/', $this->value);
                } else {
                    $pathValue = preg_replace('/\/+/', '/', $this->value.$pathAttribute->getValue());
                }

                $GET_OF_METHOD = ((yield GET::findByMethod($method)) ?? $GET) ?? new GET();
                $DELETE_OF_METHOD = (yield DELETE::findByMethod($method)) ?? $DELETE;
                $POST_OF_METHOD = (yield POST::findByMethod($method)) ?? $POST;
                $PUT_OF_METHOD = (yield PUT::findByMethod($method)) ?? $PUT;
                $COPY_OF_METHOD = (yield COPY::findByMethod($method)) ?? $COPY;
                $HEAD_OF_METHOD = (yield HEAD::findByMethod($method)) ?? $HEAD;
                $LINK_OF_METHOD = (yield LINK::findByMethod($method)) ?? $LINK;
                $LOCK_OF_METHOD = (yield LOCK::findByMethod($method)) ?? $LOCK;
                $OPTIONS_OF_METHOD = (yield OPTIONS::findByMethod($method)) ?? $OPTIONS;
                $PATCH_OF_METHOD = (yield PATCH::findByMethod($method)) ?? $PATCH;
                $PROPFIND_OF_METHOD = (yield PROPFIND::findByMethod($method)) ?? $PROPFIND;
                $PURGE_OF_METHOD = (yield PURGE::findByMethod($method)) ?? $PURGE;
                $UNKNOWN_OF_METHOD = (yield UNKNOWN::findByMethod($method)) ?? $UNKNOWN;
                $UNLINK_OF_METHOD = (yield UNLINK::findByMethod($method)) ?? $UNLINK;
                $UNLOCK_OF_METHOD = (yield UNLOCK::findByMethod($method)) ?? $UNLOCK;


                if ($GET_OF_METHOD) {
                    yield Route::get($pathValue, $method->getClosure($instance));
                }
                if ($DELETE_OF_METHOD) {
                    yield Route::delete($pathValue, $method->getClosure($instance));
                }
                if ($POST_OF_METHOD) {
                    yield Route::post($pathValue, $method->getClosure($instance));
                }
                if ($PUT_OF_METHOD) {
                    yield Route::put($pathValue, $method->getClosure($instance));
                }
                if ($COPY_OF_METHOD) {
                    yield Route::copy($pathValue, $method->getClosure($instance));
                }
                if ($HEAD_OF_METHOD) {
                    yield Route::head($pathValue, $method->getClosure($instance));
                }
                if ($LINK_OF_METHOD) {
                    yield Route::link($pathValue, $method->getClosure($instance));
                }
                if ($LOCK_OF_METHOD) {
                    yield Route::lock($pathValue, $method->getClosure($instance));
                }
                if ($OPTIONS_OF_METHOD) {
                    yield Route::options($pathValue, $method->getClosure($instance));
                }
                if ($PATCH_OF_METHOD) {
                    yield Route::patch($pathValue, $method->getClosure($instance));
                }
                if ($PROPFIND_OF_METHOD) {
                    yield Route::propfind($pathValue, $method->getClosure($instance));
                }
                if ($PURGE_OF_METHOD) {
                    yield Route::purge($pathValue, $method->getClosure($instance));
                }
                if ($UNKNOWN_OF_METHOD) {
                    yield Route::unknown($pathValue, $method->getClosure($instance));
                }
                if ($UNLINK_OF_METHOD) {
                    yield Route::unlink($pathValue, $method->getClosure($instance));
                }
                if ($UNLOCK_OF_METHOD) {
                    yield Route::unlock($pathValue, $method->getClosure($instance));
                }
            }
        });
    }
}
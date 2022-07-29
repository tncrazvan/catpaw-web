<?php

namespace CatPaw\Web\Attributes;

use Amp\LazyPromise;
use Amp\Promise;
use Attribute;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Utilities\ReflectionTypeManager;
use CatPaw\Utilities\Strings;
use CatPaw\Web\HttpContext;
use Exception;
use ReflectionParameter;

#[Attribute]
class RequestQuery implements AttributeInterface {
    use CoreAttributeDefinition;

    public function __construct(
        private string $name = ''
    ) {
    }

    public function setName(string $name): void {
        $this->name = $name;
    }

    public function getName(): string {
        return $this->name;
    }

    public function onParameter(ReflectionParameter $reflection, mixed &$value, mixed $context): Promise {
        /** @var HttpContext $context */
        return new LazyPromise(function() use (
            $reflection,
            &$value,
            $context
        ) {
            $type = ReflectionTypeManager::unwrap($reflection);
            $key  = '' === $this->name?$reflection->getName():$this->name;
            if (!$type) {
                die(Strings::red("Handler \"$context->eventID\" must specify at least 1 type for query \"$key\".\n"));
            }
            $typeName = $type->getName();

            $result = match ($typeName) {
                "string" => $this->toString($context, $key),
                "int"    => $this->toInteger($context, $key),
                "float"  => $this->toFloat($context, $key),
                "bool"   => $this->toBool($context, $key),
            };
            if ($result) {
                $value = $result;
            } elseif (!$reflection->allowsNull()) {
                die(Strings::red("Handler \"$context->eventID\" specifies a request query string parameter that is not nullable. Any request query string parameter MUST be nullable.\n"));
            }
        });
    }

    /**
     * @param  HttpContext  $http
     * @return false|string
     */
    public function toString(HttpContext $http, string $key): false|string {
        if (isset($http->query[$key])) {
            return urldecode($http->query[$key]);
        }
        return false;
    }


    /**
     * @param  HttpContext $http
     * @throws Exception
     * @return false|int
     */
    private function toInteger(HttpContext $http, string $key): false|int {
        if (isset($http->query[$key])) {
            $value = urldecode($http->query[$key]);
            if (is_numeric($value)) {
                return (int)$value;
            } else {
                throw new Exception("RequestQuery $key was expected to be numeric, but non numeric value has been provided instead:$value");
            }
        }
        return false;
    }


    /**
     * @param  HttpContext $http
     * @return bool
     */
    private function toBool(HttpContext $http, string $key): bool {
        if (isset($http->query[$key])) {
            return filter_var(urldecode($http->query[$key]), FILTER_VALIDATE_BOOLEAN);
        }
        return false;
    }

    /**
     * @param  HttpContext $http
     * @throws Exception
     * @return false|float
     */
    private function toFloat(HttpContext $http, string $key): false|float {
        if (isset($http->query[$key])) {
            $value = urldecode($http->query[$key]);
            if (is_numeric($value)) {
                return (float)$value;
            } else {
                throw new Exception("RequestQuery $key was expected to be numeric, but non numeric value has been provided instead:$value");
            }
        }
        return false;
    }
}

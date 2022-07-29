<?php

namespace CatPaw\Web\Attributes;

use Amp\LazyPromise;
use Amp\Promise;
use Attribute;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Utilities\Strings;
use CatPaw\Web\HttpContext;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

#[Attribute]
class Param implements AttributeInterface {
    use CoreAttributeDefinition;

    public function __construct(
        private string $regex = '',
    ) {
    }

    public function getRegex(): string {
        return $this->regex;
    }

    public function setRegex(
        string $regex
    ): void {
        $this->regex = $regex;
    }

    private static array $cache = [];

    public function onParameter(ReflectionParameter $reflection, mixed &$value, mixed $context): Promise {
        /** @var false|HttpContext $http */
        return new LazyPromise(function() use (
            $reflection,
            &$value,
            $context
        ) {
            $name = $reflection->getName();
            if (!isset(self::$cache[$context->eventID])) {
                $type = $reflection->getType();
                if ($type instanceof ReflectionUnionType) {
                    $typeName = $type->getTypes()[0]->getName();
                } elseif ($type instanceof ReflectionType) {
                    $typeName = $type->getName();
                } else {
                    die(Strings::red("Handler \"$context->eventID\" must specify at least 1 type path parameter \"$name\".\n"));
                }

                self::$cache[$context->eventID] = $typeName;
            }

            $cname = self::$cache[$context->eventID];

            $value = $context->params[$name] ?? false;

            if ('y' === $value) {
                $value = true;
            } elseif ('n' === $value) {
                $value = false;
            }

            if ("bool" === $cname) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        });
    }
}

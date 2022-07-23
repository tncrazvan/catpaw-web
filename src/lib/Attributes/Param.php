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
use ReflectionParameter;

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
                $type = ReflectionTypeManager::unwrap($reflection);
                if (!$type) {
                    die(Strings::red("Handler \"$context->eventID\" must specify at least 1 type path parameter \"$name\".\n"));
                }

                $typeName = $type->getName();

                self::$cache[$context->eventID] = $typeName;
            }

            $cname = self::$cache[$context->eventID];

            $value = $context->params[$name] ?? false;

            if ("bool" === $cname) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        });
    }
}

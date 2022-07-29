<?php

namespace CatPaw\Web\Attributes;

use Amp\LazyPromise;
use Amp\Promise;
use Attribute;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Utilities\ReflectionTypeManager;
use CatPaw\Web\HttpContext;
use ReflectionParameter;

#[Attribute]
class RequestHeader implements AttributeInterface {
    use CoreAttributeDefinition;

    public function __construct(private string $key) {
    }

    public function getKey():string {
        return $this->key;
    }

    public function onParameter(ReflectionParameter $reflection, mixed &$value, mixed $context): Promise {
        /** @var false|HttpContext $http */
        return new LazyPromise(function() use (
            $reflection,
            &$value,
            $context
        ) {
            $type = ReflectionTypeManager::unwrap($reflection);
            $name = $type?$type->getName():'';

            $value = match ($name) {
                'string' => $context->request->getHeader($this->key),
                'bool'   => (bool)$context->request->getHeader($this->key),
                'int'    => (int)$context->request->getHeader($this->key),
                'double' => (double)$context->request->getHeader($this->key),
                'float'  => (float)$context->request->getHeader($this->key),
                'array'  => $context->request->getHeaderArray($this->key),
                default  => $context->request->getHeaderArray($this->key),
            };
        });
    }
}

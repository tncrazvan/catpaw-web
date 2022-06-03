<?php

namespace CatPaw\Web\Attributes;

use Amp\LazyPromise;
use Amp\Promise;
use Attribute;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Web\HttpContext;
use ReflectionParameter;

#[Attribute]
class RequestHeader implements AttributeInterface {
    use CoreAttributeDefinition;

    public function __construct(private string $key) {
    }

    public function onParameter(ReflectionParameter $reflection, mixed &$value, mixed $http): Promise {
        /** @var false|HttpContext $http */
        return new LazyPromise(function() use (
            $reflection,
            &$value,
            $http
        ) {
            $value = match ($reflection->getType()->getName()) {
                'string' => $http->request->getHeader($this->key),
                'bool'   => (bool)$http->request->getHeader($this->key),
                'int'    => (int)$http->request->getHeader($this->key),
                'double' => (double)$http->request->getHeader($this->key),
                'float'  => (float)$http->request->getHeader($this->key),
                'array'  => $http->request->getHeaderArray($this->key),
                default  => $http->request->getHeader($this->key),
            };
        });
    }
}

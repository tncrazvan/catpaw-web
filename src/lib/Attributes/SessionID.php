<?php
namespace CatPaw\Web\Attributes;

use function Amp\call;
use Amp\Promise;
use Attribute;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Web\HttpContext;
use ReflectionParameter;

#[Attribute]
class SessionID implements AttributeInterface {
    use CoreAttributeDefinition;


    public function onParameter(ReflectionParameter $reflection, mixed &$value, mixed $context): Promise {
        return call(function() use ($context, &$value) {
            /** @var HttpContext $context */
            $value = $context->response->getCookie("session-id")?->getValue() ?? $context->request->getCookie("session-id")?->getValue() ?? null;
        });
    }
}
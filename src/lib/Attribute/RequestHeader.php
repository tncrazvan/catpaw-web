<?php
namespace CatPaw\Web\Attribute;

use Amp\LazyPromise;
use Amp\Promise;
use Attribute;
use CatPaw\Attribute\Interface\AttributeInterface;
use CatPaw\Attribute\Trait\CoreAttributeDefinition;
use CatPaw\Web\HttpContext;
use ReflectionParameter;

#[Attribute]
class RequestHeader implements AttributeInterface {
	use CoreAttributeDefinition;

	public function __construct(private string $key) { }

	public function onParameter(ReflectionParameter $reflection, mixed &$value, mixed $http): Promise {
		/** @var false|HttpContext $http */
		return new LazyPromise(function() use (
			$reflection,
			&$value,
			$http
		) {
			$value = $http->request->getHeaderArray($this->key);
		});
	}
}
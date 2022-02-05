<?php

namespace CatPaw\Web\Attribute\Http;

use Amp\LazyPromise;
use Amp\Promise;
use Attribute;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Tools\Strings;
use CatPaw\Web\Http\HttpContext;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

#[Attribute]
class PathParam implements AttributeInterface {
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

	public function onParameter(ReflectionParameter $reflection, mixed &$value, false|HttpContext $http): Promise {
		return new LazyPromise(function() use (
			$reflection,
			&$value,
			$http
		) {
			$name = $reflection->getName();
			if(!isset(self::$cache["$http->eventID:$name"])) {
				$type = $reflection->getType();
				if($type instanceof ReflectionUnionType) {
					$typeName = $type->getTypes()[0]->getName();
				} else if($type instanceof ReflectionType) {
					$typeName = $type->getName();
				} else {
					die(Strings::red("Handler \"$http->eventID\" must specify at least 1 type path parameter \"$name\".\n"));
				}

				self::$cache[$http->eventID] = $typeName;
			}

			$cname = self::$cache[$http->eventID];

			$value = $http->params[$name]??false;

			if('y' === $value) $value = true;
			else if('n' === $value) $value = false;

			if("bool" === $cname)
				$value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
		});
	}
}
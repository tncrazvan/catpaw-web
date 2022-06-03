<?php
namespace CatPaw\Web\Attributes;

use Attribute;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;

#[Attribute]
class Filters implements AttributeInterface {
    use CoreAttributeDefinition;
    
    private array $classNames = [];

    public function __construct(string ...$classNames) {
        $this->classNames = $classNames;
    }

    public function getClassNames():array {
        return $this->classNames;
    }
}
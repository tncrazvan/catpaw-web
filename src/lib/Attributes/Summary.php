<?php
namespace CatPaw\Web\Attributes;

use Attribute;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;

#[Attribute]
class Summary implements AttributeInterface {
    use CoreAttributeDefinition;
    
    public function __construct(private string $value) {
    }

    public function getValue():string {
        return $this->value;
    }
}
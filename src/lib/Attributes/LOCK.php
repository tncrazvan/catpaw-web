<?php
namespace CatPaw\Web\Attributes;

use Attribute;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;

#[Attribute]
class LOCK implements AttributeInterface {
    use CoreAttributeDefinition;
}
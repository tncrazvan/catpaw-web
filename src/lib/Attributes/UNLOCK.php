<?php
namespace CatPaw\Web\Attributes;

use Attribute;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;

#[Attribute]
class UNLOCK implements AttributeInterface {
    use CoreAttributeDefinition;
}
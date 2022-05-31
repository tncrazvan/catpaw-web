<?php
namespace CatPaw\Web\Attributes;

use Attribute;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;

#[Attribute]
class PURGE implements AttributeInterface {
    use CoreAttributeDefinition;
}
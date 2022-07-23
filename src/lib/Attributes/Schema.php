<?php
namespace CatPaw\Web\Attributes;

use Attribute;
use CatPaw\Attributes\Entry;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Web\Services\OpenAPIService;

#[Attribute]
class Schema implements AttributeInterface {
    use CoreAttributeDefinition;
    
    private array $schema = [];

    public function __construct(
        private string $type
    ) {
    }

    public function getValue():array {
        return $this->schema;
    }

    #[Entry] public function setup(
        OpenAPIService $api
    ):void {
        $this->schema = $api->createSchema(
            type: $this->type,
        );
    }
}
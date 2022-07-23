<?php
namespace CatPaw\Web\Attributes;

use Attribute;
use CatPaw\Attributes\Entry;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Web\Services\OpenAPIService;

#[Attribute]
class Example implements AttributeInterface {
    use CoreAttributeDefinition;
    
    private array $example = [];

    public function __construct(
        private string $title,
        private Summary $summary,
        private array|string|int|float|bool $value,
    ) {
    }

    public function getValue():array {
        return $this->example;
    }

    #[Entry] public function setup(
        OpenAPIService $api
    ):void {
        $this->example = $api->createExample(
            title:$this->title,
            summary:$this->summary->getValue(),
            value:$this->value,
        );
    }
}
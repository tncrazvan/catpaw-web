<?php

namespace CatPaw\Web\Attributes;

use Attribute;
use CatPaw\Attributes\Entry;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Web\Services\OpenAPIService;

/**
 * Attatch to a function or method.
 *
 * This tell catpaw what type of content it produces.<br/>
 * Example of possible values:
 * - text/plain
 * - application/json
 */
#[Attribute]
class Produces implements AttributeInterface {
    use CoreAttributeDefinition;

    private array $contentType;
    private array $responses;
    private array $productions;

    /**
     * @param Response|string ...$productions http content type consumed as strings or 
     *                                        the whole shape of available responses as Response objects.
     *                                        Mixing both strings and Responses is allowed.
     */
    public function __construct(
        ProducedResponse|string ...$productions,
    ) {
        $this->productions = $productions;
    }

    #[Entry] public function setup(OpenAPIService $api) {
        $this->contentType = [];
        $this->responses   = [];

        foreach ($this->productions as $production) {
            if ($production instanceof ProducedResponse) {
                $production->setup($api);
                $this->responses[]   = $production;
                $this->contentType[] = $production->getContentType();
            } else {
                $this->contentType[] = $production;
            }
        }
    }

    /**
     * Get the types of content available to generate.
     *
     * @return array<string>
     */
    public function getContentType(): array {
        return $this->contentType;
    }

    /**
     * Get the shaped responses available to generate.
     *
     * @return array<ProducedResponse>
     */
    public function getProducedResponses():array {
        return $this->responses;
    }
}

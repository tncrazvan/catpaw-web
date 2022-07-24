<?php
namespace CatPaw\Web\Attributes;

use Attribute;
use CatPaw\Attributes\Entry;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Web\Services\OpenAPIService;

#[Attribute]
class ProducedResponse implements AttributeInterface {
    use CoreAttributeDefinition;
    
    private array $response = [];

    /**
     * Undocumented function
     *
     * @param int                         $status      http status code
     * @param string                      $type        http content-type. When passed to #[Produces], this content-type will be enforced.
     * @param array<string,string>|string $schema      shape of the response
     * @param string                      $description
     */
    public function __construct(
        private string $type = 'text/plain',
        private int $status = 200,
        private array|string $schema = '',
        private string $description = '',
        private array|string|int|float|bool|null $example = null,
    ) {
    }

    public function getStatus():int {
        return $this->status;
    }
    public function getContentType():string {
        return $this->type;
    }

    public function getValue():array {
        return $this->response;
    }

    private function unwrap(OpenAPIService $api, array $schema):array {
        $properties = [];
        $len        = count($schema);
        if (1 === $len && isset($schema)) {
            return [
                "type"  => "array",
                "items" => $this->unwrap($api, $schema[0]),
            ];
        }

        foreach ($schema as $key => $type) {
            if (\is_array($type)) {
                if (count($type) === 0) {
                    continue;
                }

                if (!isset($type[0])) {
                    $localProperties  = $this->unwrap($api, $type);
                    $properties[$key] = $localProperties;
                    continue;
                }

                $type = $type[0];

                if (\is_array($type)) {
                    $properties[$key] = [
                        "type"  => "array",
                        "items" => $this->unwrap($api, $type),
                    ];
                } else {
                    $type             = \explode("\\", $type);
                    $type             = $type[\count($type) - 1];
                    $properties[$key] = [
                        "type"  => "array",
                        "items" => [
                            "type" => $type,
                        ],
                    ];
                }
            } else {
                $type             = \explode("\\", $type);
                $type             = $type[\count($type) - 1];
                $properties[$key] = [ "type" => $type ];
            }
        }
        return [
            "type"       => "object",
            "properties" => $properties,
        ];
    }

    #[Entry] public function setup(
        OpenAPIService $api
    ):void {
        $schema         = is_array($this->schema)?$this->unwrap($api, $this->schema):[ "type" => $this->schema ];
        $this->response = $api->createResponse(
            status:$this->status,
            description: $this->description,
            contentType: $this->type,
            schema: $schema,
            example: $this->example,
        );
    }
}
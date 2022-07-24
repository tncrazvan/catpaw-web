<?php

namespace CatPaw\Web\Services;

use CatPaw\Attributes\Service;

#[Service]
class OpenAPIService {
    private array $json = [
        'openapi' => '3.0.0',
        'info'    => [
            'title'   => 'OpenAPI',
            'version' => '0.0.1',
        ],
        'paths' => []
    ];

    /**
     * Get the current OpenAPI data.
     *
     * @return array
     */
    public function getData():array {
        return $this->json;
    }

    public function setTitle(string $title):void {
        $this->json['info']['title'] = $title;
    }
    
    public function setVersion(string $title):void {
        $this->json['info']['version'] = $title;
    }

    public function setPath(string $path, array $pathContent):void {
        $this->json['paths'][$path] = $pathContent;
    }


    /**
     * Create a deterministic ID for an operation.
     * Given the same inputs this function will always return the same ID.
     *
     * @param  string $method     http method
     * @param  array  $parameters operation parameters
     * @param  array  $responses  operation responses
     * @return string
     */
    public function createOperationID(
        string $method,
        array $parameters,
        array $responses,
    ):string {
        $parametersIDs = [];
        $responsesKeys = \join('-', \array_keys($responses));
        foreach ($parameters as $key => $parameter) {
            $name            = $parameter['name'];
            $in              = $parameter['in'];
            $parametersIDs[] = "n.$name;i.$in";
        }
        $parametersIDs = \join(';', $parametersIDs);
        return \sha1("$method:$parametersIDs:$responsesKeys");
    }

    public function createPathContent(
        string $method,
        string $operationID,
        string $summary,
        array $parameters,
        array $responses,
    ):array {
        $method = \strtolower($method);
        return [
            "$method" => [
                "summary"     => $summary,
                "operationId" => $operationID,
                "parameters"  => $parameters,
                "responses"   => $responses,
            ],
        ];
    }

    public function createParameter(
        string $name,
        string $in,
        string $description,
        bool $required,
        array $schema,
        array $examples,
    ):array {
        return [[
            "name"        => $name,
            "in"          => $in,
            "description" => $description,
            "required"    => $required,
            "schema"      => $schema,
            "examples"    => $examples,
        ]];
    }

    public function createResponse(
        int $status,
        string $description,
        string $contentType,
        array $schema,
    ):array {
        return [
            "$status" => [
                "description" => $description,
                "content"     => [
                    "$contentType" => [
                        "schema" => $schema,
                    ],
                ],
            ],
        ];
    }

    public function createSchema(
        string $type,
        array|null $properties = null,
    ):array {
        if (null !== $properties) {
            return [
                "type"       => $type,
                "properties" => $properties,
            ];
        }
        return [
            "type" => $type,
        ];
    }

    public function createProperty(
        string $name,
        string $type,
        string $description,
    ):array {
        return [
            "$name" => [
                "type"        => $type,
                "description" => $description,
            ],
        ];
    }

    public function createExample(
        string $title,
        string $summary,
        array|string|int|float|bool $value,
    ):array {
        return [
            "$title" => [
                "summary" => $summary,
                "value"   => $value,
            ],
        ];
    }
}
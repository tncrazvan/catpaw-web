<?php
namespace Tests\Controller;

use CatPaw\Web\Attributes\GET;
use CatPaw\Web\Attributes\Param;
use CatPaw\Web\Attributes\Path;
use CatPaw\Web\Attributes\ProducedResponse;
use CatPaw\Web\Attributes\Produces;
use CatPaw\Web\Attributes\Summary;
use CatPaw\Web\Services\OpenAPIService;

#[Path('/')]
#[Produces("text/html")]
class SampleController {
    #[Produces("text/plain")]
    public function hello() {
        return "hello";
    }

    #[GET]
    #[Path("/object/{username}")]
    #[Produces(new ProducedResponse('application/json'))]
    public function helloObject(
        #[Param] string $username,
    ) {
        return [
            "username" => $username,
            "created"  => time(),
            "active"   => true
        ];
    }

    #[GET]
    #[Path("/{username}")]
    #[Summary("Get information about an user")]
    #[Produces("text/html")]
    public function helloUser(
        #[Param] string $username,
    ) {
        return "hello $username";
    }

    #[GET]
    #[Path("/openapi")]
    public function openAPI(
        OpenAPIService $api
    ) {
        return $api->getData();
    }
}
<?php
namespace Tests\Controller;

use CatPaw\Web\Attributes\GET;
use CatPaw\Web\Attributes\Param;
use CatPaw\Web\Attributes\Path;
use CatPaw\Web\Attributes\Produces;
use CatPaw\Web\Attributes\RequestQuery;
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
    #[Path("/{username}")]
    #[Summary("Get information about an user")]
    public function helloUser(
        #[Param] string $username,
        #[RequestQuery] ?string $token,
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
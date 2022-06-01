<?php
namespace Tests\Controller;

use CatPaw\Web\Attributes\GET;
use CatPaw\Web\Attributes\Param;
use CatPaw\Web\Attributes\Path;
use CatPaw\Web\Attributes\Produces;

#[Path('/')]
#[Produces("text/html")]
class SampleController {
    #[Produces("text/plain")]
    public function hello() {
        return "hello";
    }

    #[GET]
    #[Path("/{username}")]
    public function helloUser(
        #[Param] string $username
    ) {
        return "hello $username";
    }
}
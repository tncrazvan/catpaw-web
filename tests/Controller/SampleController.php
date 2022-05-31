<?php
namespace Tests\Controller;

use CatPaw\Web\Attributes\GET;
use CatPaw\Web\Attributes\Param;
use CatPaw\Web\Attributes\Path;

#[Path('/')]
class SampleController {
    #[GET]
    #[Path("/")]
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
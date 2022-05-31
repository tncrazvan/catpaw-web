<?php
namespace Tests\Controller;

use CatPaw\Web\Attributes\GET;
use CatPaw\Web\Attributes\Path;

#[Path('/')]
class SampleController {
    #[GET]
    public function test() {
        return "asd";
    }
}
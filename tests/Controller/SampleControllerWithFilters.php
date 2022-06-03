<?php
namespace Tests\Controller;

use CatPaw\Web\Attributes\Filters;
use CatPaw\Web\Attributes\GET;
use CatPaw\Web\Attributes\Path;

#[Path('/test')]
#[Filters(MyFilter::class)]
class SampleControllerWithFilters {
    #[GET]
    public function test() {
        return "ok";
    }
}
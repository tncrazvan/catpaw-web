<?php

namespace CatPaw\Web\Utilities;

use CatPaw\Web\Attributes\Consumes;
use CatPaw\Web\Attributes\GET;
use CatPaw\Web\Attributes\Path;
use CatPaw\Web\Attributes\Produces;
use CatPaw\Web\Attributes\PUT;

use CatPaw\Web\Attributes\RequestBody;

abstract class SPA {
    protected abstract function setState($state);
    protected abstract function getState();
    

    #[GET]
    #[Path(":state")]
    #[Produces("application/json")]
    public function get() {
        return $this->getState();
    }

    #[PUT]
    #[Path(":state")]
    #[Consumes("application/json")]
    public function put(
        #[RequestBody] array $state
    ) {
        $this->setState($state);
    }
}
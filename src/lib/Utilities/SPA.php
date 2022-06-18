<?php

namespace CatPaw\Web\Utilities;

use CatPaw\Web\Attributes\Consumes;
use CatPaw\Web\Attributes\GET;
use CatPaw\Web\Attributes\Path;
use CatPaw\Web\Attributes\Produces;
use CatPaw\Web\Attributes\PUT;

use CatPaw\Web\Attributes\RequestBody;
use DomainException;

abstract class SPA {
    protected abstract function setState(array $state);
    protected abstract function getState():array;

    private function isAssoc(array $arr) {
        if ([] === $arr) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    #[GET]
    #[Path(":state")]
    #[Produces("application/json")]
    public function get():array {
        $state = $this->getState();

        if (!$this->isAssoc($state)) {
            throw new DomainException("All SPA states must be associative arrays.");
        }

        return $state;
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
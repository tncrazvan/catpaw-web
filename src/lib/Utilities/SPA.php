<?php

namespace CatPaw\Web\Utilities;

use CatPaw\Web\Attributes\Consumes;
use CatPaw\Web\Attributes\GET;
use CatPaw\Web\Attributes\Path;
use CatPaw\Web\Attributes\Produces;
use CatPaw\Web\Attributes\PUT;

use CatPaw\Web\Attributes\RequestBody;
use CatPaw\Web\Attributes\Session;
use CatPaw\Web\Attributes\SessionID;
use DomainException;

abstract class SPA {
    protected abstract function setState(array $state, array &$session):void;
    protected abstract function getState(callable $id, array &$session):array;

    /**
     * See credits.
     * @see https://stackoverflow.com/a/173479
     * @param  array $arr
     * @return bool
     */
    private function isAssoc(array $arr) {
        if ([] === $arr) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    #[GET]
    #[Path(":state")]
    #[Produces("application/json")]
    public function get(
        #[Session] array &$session,
        #[SessionID] ?string $sessionID
    ):array {
        $state = $this->getState(fn(string $id) => \sha1(static::class.":$sessionID:$id"), $session);

        if (!$this->isAssoc($state)) {
            throw new DomainException("All SPA states must be associative arrays.");
        }

        return $state;
    }

    #[PUT]
    #[Path(":state")]
    #[Consumes("application/json")]
    public function put(
        #[RequestBody] array $state,
        #[Session] array &$session,
    ) {
        $this->setState($state, $session);
    }
}
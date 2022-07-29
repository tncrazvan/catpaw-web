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
use function CatPaw\Web\lazy;
use DomainException;

use ReflectionClass;
use stdClass;

abstract class SPA {
    private string $SPAPath = '';
    
    protected array $state  = [];
    protected bool $updated = false;
    
    /**
     * Set the SPA state
     *
     * @param  array $state new state
     * @return void
     */
    private function setState(array $state, array &$session): void {
        $this->state   = $state;
        $this->updated = true;
    }
    /**
     * get the SPA state
     *
     * @param  callable(string $value):string $path  takes a string as a parameter and returns an unique path for your lazy property
     * @return array
     */
    private function getState(callable $path, array &$session): array {
        return $this->updated?$this->state:lazy($path, $session, $this->state);
    }

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

    private bool $initialized = false;

    #[GET]
    #[Path(":state")]
    #[Produces("application/json")]
    public function get(
        #[Session] array &$session,
        #[SessionID] ?string $sessionID
    ) {
        if (!$this->initialized) {
            /** @var Path */
            $path              = yield Path::findByClass(new ReflectionClass(static::class));
            $this->SPAPath     = $path->getValue();
            $this->initialized = true;
        }

        $state = $this->getState(fn(string $id) => "$this->SPAPath:lazy:$sessionID:$id", $session);

        if ($state && !$this->isAssoc($state)) {
            throw new DomainException("All SPA states must be associative arrays.");
        }

        return !$state? new stdClass():$state;
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
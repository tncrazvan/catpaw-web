<?php

namespace CatPaw\Web\Utilities;

use CatPaw\Web\Attributes\GET;
use CatPaw\Web\Attributes\Path;
use CatPaw\Web\Attributes\Produces;

use CatPaw\Web\Attributes\Session;
use CatPaw\Web\Attributes\SessionID;
use function CatPaw\Web\lazy;

use ReflectionClass;

abstract class SPA {
    private string $SPAPath = '';
    
    protected array $state  = [];
    protected bool $updated = false;
    
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
        #[SessionID] string $sessionID
    ) {
        if (!$this->initialized) {
            /** @var Path */
            $path              = yield Path::findByClass(new ReflectionClass(static::class));
            $this->SPAPath     = $path->getValue();
            $this->initialized = true;
        }

        $key = "$this->SPAPath:lazy:$sessionID";

        if (isset($this->paths[$key])) {
            return $this->paths[$key];
        }

        $this->paths[$key] = lazy(fn(string $id) => "$key:$id", $session, $this->state);

        return $this->paths[$key];
    }
}
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
    private bool $initialized = false;    
    private string $SPAPath   = '';
    private array $paths      = [];
    /**
     * Initial state of the user.
     * All new sessions will inherit this state as the default state.
     * @var array
     */
    protected array $state = [];


    #[GET]
    #[Path(":state")]
    #[Produces("application/json")]
    public function getState(
        #[Session] array &$session,
        #[SessionID] string $sessionID
    ) {
        if (!$this->initialized) {
            /** @var Path */
            $path              = yield Path::findByClass(new ReflectionClass(static::class));
            $this->SPAPath     = $path->getValue();
            $this->initialized = true;
        }

        $key = "$sessionID:$this->SPAPath";

        if (isset($this->paths[$key])) {
            return $this->paths[$key];
        }

        $this->paths[$key] = lazy(fn(string $id) => sha1("$key:$id"), $session, $this->state);

        return $this->paths[$key];
    }
}
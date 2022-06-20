<?php

namespace CatPaw\Web\Utilities;

use CatPaw\Web\Attributes\Consumes;
use CatPaw\Web\Attributes\Produces;
use CatPaw\Web\Attributes\RequestBody;
use Closure;

class Lazy {
    private Closure|false $onUpdate;
    private bool $published = false;
    private $lastCascade    = null;
    public function __construct(
        private string $path,
        private string $key,
        private mixed &$value,
    ) {
    }

    /**
     * Expose the property through http.
     *
     * @return static
     */
    public function publish():static {
        if ($this->published) {
            return $this;
        }
        $this->published = true;
        Route::get($this->path, #[Produces("application/json")] function() {
            return [ $this->key => $this->value ];
        });
        Route::put($this->path, #[Consumes("application/json")] function(
            #[RequestBody] array $payload
        ) {
            $this->value = $payload[$this->key];
            if ($this->onUpdate) {
                ($this->onUpdate)($this->value);
            }
        });
        return $this;
    }

    /**
     * Push updates to a given variable
     * @param  mixed  $cascade variable to update
     * @return static
     */
    public function push(&$cascade):static {
        if (null !== $this->lastCascade && $this->lastCascade === $cascade) {
            return $this;
        }

        if ($cascade) {
            $this->value = $cascade;
        }

        $this->onUpdate = function($value) use (&$cascade) {
            $cascade = $value;
        };
        return $this;
    }

    /**
     * Build the metadata for the property.<br/>
     * This metadata should be used by the frontend client to manage the property.
     * @return array
     */
    public function build():array {
        return [ $this->key => $this->path ];
    }
}
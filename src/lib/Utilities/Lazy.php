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
        private mixed &$value,
    ) {
    }

    public function publish():static {
        if ($this->published) {
            return $this;
        }
        $this->published = true;
        Route::get($this->path, #[Produces("application/json")] function() {
            return [ '!lazy' => $this->value ];
        });
        Route::put($this->path, #[Consumes("application/json")] function(
            #[RequestBody] array $payload
        ) {
            $this->value = $payload['!lazy'];
            if ($this->onUpdate) {
                ($this->onUpdate)($this->value);
            }
        });
        return $this;
    }

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

    public function build():array {
        return [ '!lazy' => $this->path ];
    }
}
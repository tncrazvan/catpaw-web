<?php

namespace CatPaw\Web\Utilities;

use CatPaw\Web\Attributes\Consumes;
use CatPaw\Web\Attributes\IgnoreDescribe;
use CatPaw\Web\Attributes\IgnoreOpenAPI;
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
        Route::get(
            path: $this->path,
            callback: 
            #[IgnoreOpenAPI]
            #[IgnoreDescribe]
            #[Produces("application/json")]
            function() {
                return [ '!lazy' => $this->value ];
            }
        );
        Route::put(
            path: $this->path,
            callback: 
            #[IgnoreOpenAPI] 
            #[IgnoreDescribe] 
            #[Consumes("application/json")] 
            function(
                #[RequestBody] array $payload
            ) {
                $this->value = $payload['!lazy'];
                if ($this->onUpdate ?? false) {
                    ($this->onUpdate)($this->value);
                }
            }
        );
        return $this;
    }

    public function bind(&$target):static {
        if (null !== $this->lastCascade && $this->lastCascade === $target) {
            return $this;
        }

        if ($target) {
            $this->value = $target;
        }

        $this->onUpdate = function($value) use (&$target) {
            $target     = $value;
        };
        return $this;
    }

    public function build():array {
        return [ '!lazy' => $this->path ];
    }
}
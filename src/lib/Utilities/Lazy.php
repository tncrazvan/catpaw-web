<?php

namespace CatPaw\Web\Utilities;

use Amp\Http\Server\Response;
use Amp\Http\Status;
use CatPaw\Web\Attributes\Consumes;
use CatPaw\Web\Attributes\Param;
use CatPaw\Web\Attributes\Produces;
use CatPaw\Web\Attributes\RequestBody;
use Closure;

class Lazy {
    private static array $list  = [];
    private static bool $routed = false;
    private Closure|false $onUpdate;
    private bool $published = false;
    private $lastCascade    = null;
    public function __construct(
        private string $id,
        private mixed &$value,
    ) {
    }

    public function setValue(mixed &$value):self {
        $this->value = $value;
        return $this;
    }

    public function &getValue():mixed {
        return $this->value;
    }
    public function getOnUpdate():Closure|false {
        return $this->onUpdate;
    }

    private static function findByID(string $id):false|Lazy {
        return self::$list[$id] ?? false;
    }
    
    public static function route() {
        if (self::$routed) {
            return;
        }
        self::$routed = true;
        Route::get(
            path: "/:lazy:{id}",
            callback:
            #[Produces("application/json")]
            function(
                #[Param] string $id,
            ) {
                $lazy = self::findByID($id);
                if (!$lazy) {
                    return new Response(Status::UNAUTHORIZED);
                }
                return [ '!lazy' => $lazy->getValue() ];
            }
        );
        Route::put(
            path: "/:lazy:{id}",
            callback:
            #[Consumes("application/json")] 
            function(
                #[RequestBody] array $payload,
                #[Param] string $id,
            ) {
                $lazy = self::findByID($id);
                if (!$lazy) {
                    return new Response(Status::UNAUTHORIZED);
                }
                $lazy->setValue($payload['!lazy']);
                
                if ($onUpdate = $lazy->getOnUpdate() ?? false) {
                    ($onUpdate)($lazy->getValue());
                }
            }
        );
    }

    public function publish():self {
        if ($this->published) {
            return $this;
        }
        $this->published       = true;
        self::$list[$this->id] = $this;
        self::route();
        return $this;
    }

    public function bind(&$target):self {
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
        return [ '!lazy' => $this->id ];
    }
}
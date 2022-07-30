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
    private static array $list      = [];
    private static bool $routed     = false;
    private Closure|false $onUpdate = false;
    private bool $published         = false;
    public function __construct(
        private string $id,
        private Closure $get,
        private Closure $set,
    ) {
    }

    public function setValue(mixed $value):self {
        ($this->set)($value);
        return $this;
    }

    public function getValue():mixed {
        return ($this->get)();
    }
    public function getOnUpdate():Closure|false {
        return $this->onUpdate;
    }

    public static function findByID(string $id):false|Lazy {
        return self::$list[$id] ?? false;
    }
    
    public static function route():void {
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
                
                $lazy->setValue($payload['!lazy'] ?? null);
                
                if ($onUpdate = $lazy->getOnUpdate()) {
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

    public function bind(array &$target):self {
        $this->onUpdate = function(mixed $value) use (&$target):void {
            $target     = $value;
        };
        return $this;
    }

    public function build():array {
        return [ '!lazy' => $this->id ];
    }
}
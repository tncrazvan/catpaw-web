<?php
namespace CatPaw\Web;

use CatPaw\Utilities\Strings;
use CatPaw\Web\Attributes\Consumes;
use CatPaw\Web\Attributes\Produces;
use CatPaw\Web\Attributes\RequestBody;
use CatPaw\Web\Utilities\Route;

function lazy(mixed $value):array {
    $id    = Strings::uuid();
    $path  = "/:lazy:$id";
    $key   = "__lazy;$id";
    $entry = [ $key => $path ];

    Route::get($path, #[Produces("application/json")] function() use ($key, &$value) {
        return [ $key => $value ];
    });
    Route::put($path, #[Consumes("application/json")] function(
        #[RequestBody] array $payload
    ) use (&$value, $key) {
        $value = $payload[$key];
    });

    return $entry;
}
<?php

namespace Tests\Controller;

use Amp\Http\Server\Response;
use Amp\Http\Status;
use CatPaw\Attributes\Service;
use CatPaw\Web\Attributes\Filter;
use CatPaw\Web\Attributes\RequestHeader;

#[Service]
class MyFilter {
    #[Filter]
    public function filterAuth(
        #[RequestHeader("Authorization")] ?string $authorization
    ) {
        $token = explode(' ', $authorization ?? '')[1] ?? '';
        if (!$token) {
            return new Response(Status::UNAUTHORIZED, [], "unauthorized");
        }
    }
}
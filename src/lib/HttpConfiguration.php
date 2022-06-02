<?php

namespace CatPaw\Web;

use Amp\Socket\Certificate;
use Parsedown;

class HttpConfiguration {
    /** @var string[]|string List of interfaces to bind to. */
    public array|string $httpInterfaces = "127.0.0.1:8080";

    /** @var string[]|string List of secure interfaces to bind to (requires pemCertificate). */
    public array|string|false $httpSecureInterfaces = false;

    /** @var string Directory the application should serve. */
    public string $httpWebroot = 'public';

    /** @var array<string,Certificate> an array mapping domain names to pem certificates. */
    public array $pemCertificates = [];

    /** @var bool This dictates if the stack trace should be shown to the client whenever an Exceptions is caught or not. */
    public bool $httpShowStackTrace = false;

    /** @var bool This dictates if exceptions should be shown to the client whenever an Exceptions is caught or not. */
    public bool $httpShowExceptions = false;

    /** @var Parsedown Markdown parser */
    public Parsedown $mdp;

    public function defaultCacheHeaders() {
        return [
            "Cache-Control" => "max-age=604800, public, must-revalidate, stale-while-revalidate=86400"
        ];
    }

    /**
     * Default headers for static assets.
     * @var array
     */
    public array $headers = [];
}

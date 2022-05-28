<?php

namespace CatPaw\Web\Attributes;

use function Amp\call;
use Amp\Promise;
use Amp\Socket\Certificate;
use Attribute;
use CatPaw\Attributes\Entry;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Web\HttpConfiguration;
use CatPaw\Web\WebServer;

use Psr\Log\LoggerInterface;

#[Attribute]
class StartWebServer implements AttributeInterface {
    use CoreAttributeDefinition;

    /**
     * @param array|string                                $interfaces       list of interfaces to bind to.
     * @param array|string|false                          $secureInterfaces list of secure interfaces to bind to (requires perCertificate).
     * @param string                                      $webroot          the directory the application should serve.
     * @param bool                                        $showStackTrace   if true the application will show the stack trace to the client.
     * @param bool                                        $showExceptions   if true the application will show exception messages to the client.
     * @param array<string,array{file:string,key:string}> $pemCertificates  an array mapping domain names to pem certificates (file and key).
     */
    public function __construct(
        public array|string $interfaces = "127.0.0.1:8080",
        public array|string|false $secureInterfaces = false,
        public string $webroot = 'public',
        public bool $showStackTrace = false,
        public bool $showExceptions = false,
        public array $pemCertificates = [],
        public array $headers = [],
    ) {
    }

    #[Entry]
    public function main(): Promise {
        return call(fn() => yield WebServer::start(
            interfaces: $this->interfaces,
            secureInterfaces: $this->secureInterfaces,
            webroot: $this->webroot,
            showStackTrace: $this->showStackTrace,
            showExceptions: $this->showExceptions,
            pemCertificates: $this->pemCertificates,
            headers: $this->headers,
        ));
    }
}

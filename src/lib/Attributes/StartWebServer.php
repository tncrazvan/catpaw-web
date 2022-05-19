<?php

namespace CatPaw\Web\Attributes;

use Amp\LazyPromise;
use Amp\Promise;
use Amp\Socket\Certificate;
use Attribute;
use CatPaw\Attributes\Entry;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Web\HttpConfiguration;
use CatPaw\Web\WebServer;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Log\LoggerInterface;
use Throwable;

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
    public function main(LoggerInterface $logger): Promise {
        return new LazyPromise(function() use ($logger) {
            $config = new HttpConfiguration();
            $config->pemCertificates = [];

            foreach ($this->pemCertificates as $domain => $cert) {
                $file = $cert['file'] ?? '';
                $key = $cert['key'] ?? '';
                $config->pemCertificates[$domain] = new Certificate($file, $key);
            }

            $config->httpInterfaces = $this->interfaces;
            $config->httpSecureInterfaces = $this->secureInterfaces;
            $config->httpWebroot = $this->webroot;
            $config->httpShowStackTrace = $this->showStackTrace;
            $config->httpShowExceptions = $this->showExceptions;
            $config->logger = $logger;
            yield WebServer::start($config);
        });
    }
}

<?php

namespace CatPaw\Web\Attribute\Http;

use Amp\LazyPromise;
use Amp\Promise;
use Amp\Socket\Certificate;
use Attribute;
use CatPaw\Attribute\Entry;
use CatPaw\Attribute\Interface\AttributeInterface;
use CatPaw\Attribute\Trait\CoreAttributeDefinition;
use CatPaw\Web\Http\HttpConfiguration;
use CatPaw\Web\Http\WebServer;

#[Attribute]
class StartWebServer implements AttributeInterface {
	use CoreAttributeDefinition;

	/**
	 * @param array|string       $interfaces list of interfaces to bind to.
	 * @param array|string|false $secureInterfaces list of secure interfaces to bind to (requires perCertificate).
	 * @param string             $webroot the directory the application should serve.
	 * @param bool               $showStackTrace if true the application will show the stack trace to the client.
	 * @param bool               $showException if true the application will show exception messages to the client.
	 * @param false|string       $pemCertificate path to your pem certificate.
	 */
	public function __construct(
		public array|string       $interfaces = "127.0.0.1:8080",
		public array|string|false $secureInterfaces = false,
		public string             $webroot = 'public',
		public bool               $showStackTrace = false,
		public bool               $showException = false,
		public false|string       $pemCertificate = false,
	) {

	}

	#[Entry]
	public function main(HttpConfiguration $config): Promise {
		return new LazyPromise(function() use($config) {
			if($this->pemCertificate)
				$config->pemCertificate = new Certificate($this->pemCertificate);

			$config->httpInterfaces = $this->interfaces;
			$config->httpSecureInterfaces = $this->secureInterfaces;
			$config->httpWebroot = $this->webroot;
			$config->httpShowStackTrace = $this->showStackTrace;
			$config->httpShowException = $this->showException;

			yield WebServer::start($config);
		});
	}
}
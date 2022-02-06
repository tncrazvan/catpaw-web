<?php

namespace CatPaw\Web\Http;

use Amp\Socket\Certificate;
use Closure;
use Monolog\Logger;
use Parsedown;

class HttpConfiguration {

	/** @var string[]|string List of interfaces to bind to. */
	public array|string $httpInterfaces = "127.0.0.1:8080";

	/** @var string[]|string List of secure interfaces to bind to (requires pemCertificate). */
	public array|string|false $httpSecureInterfaces = false;

	/** @var string Directory the application should serve. */
	public string $httpWebroot = 'public';

	/** @var false|Certificate Socket certificate to use for secure connections. */
	public false|Certificate $pemCertificate = false;

	/** @var false|Logger Application logger. */
	public false|Logger $logger = false;

	/** @var bool This dictates if the stack trace should be shown to the client whenever an Exception is caught or not. */
	public bool $httpShowStackTrace = false;

	/** @var bool This dictates if exceptions should be shown to the client whenever an Exception is caught or not. */
	public bool $httpShowException = false;

	/** @var Parsedown Markdown parser */
	public Parsedown $mdp;
}
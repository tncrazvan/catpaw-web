<?php

namespace CatPaw\Web;

use Amp\Socket\Certificate;
use Parsedown;
use Psr\Log\LoggerInterface;

class HttpConfiguration {

	/** @var string[]|string List of interfaces to bind to. */
	public array|string $httpInterfaces = "127.0.0.1:8080";

	/** @var string[]|string List of secure interfaces to bind to (requires pemCertificate). */
	public array|string|false $httpSecureInterfaces = false;

	/** @var string Directory the application should serve. */
	public string $httpWebroot = 'public';

	/** @var array<string,string> an array mapping domain names to pem certificates. */
	public array $pemCertificates = [];

	/** @var false|LoggerInterface Application logger. */
	public false|LoggerInterface $logger = false;

	/** @var bool This dictates if the stack trace should be shown to the client whenever an Exceptions is caught or not. */
	public bool $httpShowStackTrace = false;

	/** @var bool This dictates if exceptions should be shown to the client whenever an Exceptions is caught or not. */
	public bool $httpShowExceptions = false;

	/** @var Parsedown Markdown parser */
	public Parsedown $mdp;
}
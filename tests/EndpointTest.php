<?php

namespace Tests;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Loop;
use Amp\PHPUnit\AsyncTestCase;
use CatPaw\Web\Attributes\PathParam;
use CatPaw\Web\Utilities\Route;
use CatPaw\Web\WebServer;
use Generator;

class EndpointTest extends AsyncTestCase {
	public function testGet(): Generator {
		$http = HttpClientBuilder::buildDefault();
		yield WebServer::start(interfaces: "127.0.0.1:8000");
		Route::get("/", fn() => "hello world");
		/** @var Response $response */
		$response = yield $http->request(new Request("http://127.0.0.1:8000/"));
		$this->assertEquals("hello world", yield $response->getBody()->buffer());
		yield WebServer::stop();
		Loop::stop();
	}

	public function testGetWithParams(): Generator {
		$http = HttpClientBuilder::buildDefault();
		yield WebServer::start(interfaces: "127.0.0.1:8000");
		Route::get("/{name}", fn(#[PathParam] string $name) => "hello $name");
		/** @var Response $response */
		$response = yield $http->request(new Request("http://127.0.0.1:8000/user1"));
		$this->assertEquals("hello user1", yield $response->getBody()->buffer());
		yield WebServer::stop();
		Loop::stop();
	}

	public function testFilters(): Generator {
		$http = HttpClientBuilder::buildDefault();
		yield WebServer::start(interfaces: "127.0.0.1:8000");

		$a = function( \Amp\Http\Server\Response $response ) {
			$response->setHeader("content-type", "text/html");
			return true;
		};
		$b = function( \Amp\Http\Server\Response $response ) {
			return "ok";
		};

		Route::get("/", [$a, $b]);
		/** @var Response $response */
		$response = yield $http->request(new Request("http://127.0.0.1:8000/"));

		$this->assertEquals("text/html", $response->getHeader("content-type"));
		yield WebServer::stop();
		Loop::stop();
	}
}

<?php
namespace Tests\Controller;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Loop;
use Amp\PHPUnit\AsyncTestCase;
use CatPaw\Utilities\Container;
use CatPaw\Web\Utilities\Route;
use CatPaw\Web\WebServer;
use Tests\Controller\SampleController;

class ControllerTest extends AsyncTestCase {
    public function testPathAttributeAndDependencies() {
        $http = HttpClientBuilder::buildDefault();
        Route::clearAll();
        yield WebServer::start(interfaces: "127.0.0.1:8000");
        yield Container::load(dirname(__FILE__).'/../../composer.json');
        yield Container::run(function() use ($http) {
            $routes = Route::getAllRoutes();
            $this->assertArrayHasKey('GET', $routes);

            $this->assertArrayHasKey('/', $routes['GET'] ?? []);
            $this->assertArrayHasKey('/{username}', $routes['GET'] ?? []);


            /** @var Response $response */
            $response = yield $http->request(new Request("http://127.0.0.1:8000/", "GET"));
            $this->assertEquals("hello", yield $response->getBody()->buffer());

            /** @var Response $response */
            $response = yield $http->request(new Request("http://127.0.0.1:8000/world", "GET"));
            $this->assertEquals("hello world", yield $response->getBody()->buffer());
        });
        Loop::stop();
    }
}
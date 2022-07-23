<?php

namespace Tests;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Loop;
use CatPaw\Utilities\Container;
use CatPaw\Web\Attributes\Param;
use CatPaw\Web\Services\OpenAPIService;
use CatPaw\Web\Utilities\Route;
use CatPaw\Web\WebServer;
use PHPUnit\Framework\TestCase;

class WebServerTest extends TestCase {
    public function testWebServer() {
        Loop::run(function() {
            $http = HttpClientBuilder::buildDefault();
            yield WebServer::start(interfaces: "127.0.0.1:8000");
            yield Container::load([
                \realpath(__DIR__."/Controller")
            ]);
                
            echo PHP_EOL.Container::describe().PHP_EOL;
            echo PHP_EOL.Route::describe().PHP_EOL;
                
            yield Container::run(function(
                OpenAPIService $api
            ) use ($http) {
                yield from $this->testGet($http);
                yield from $this->testGetWithParams($http);
                yield from $this->testFilters($http);
                yield from $this->testController($http);
                yield from $this->testOpenAPI($http, $api);
            });

            
            yield WebServer::stop();
            Loop::stop();
        });
    }

    private function testGet(HttpClient $http) {
        yield Route::get("/get", fn() => "hello world");
        $response = yield $http->request(new Request("http://127.0.0.1:8000/get"));
        $this->assertEquals("hello world", yield $response->getBody()->buffer());
    }

    private function testGetWithParams(HttpClient $http) {
        yield Route::get("/get-with-params/{name}", fn(#[Param] string $name) => "hello $name");
        $response = yield $http->request(new Request("http://127.0.0.1:8000/get-with-params/user1"));
        $this->assertEquals("hello user1", yield $response->getBody()->buffer());
        $response = yield $http->request(new Request("http://127.0.0.1:8000/get-with-params/user2"));
        $this->assertEquals("hello user2", yield $response->getBody()->buffer());
    }

    private function testFilters(HttpClient $http) {
        $a = function(\Amp\Http\Server\Response $response) {
            $response->setHeader("Content-Type", "text/html");
        };
        $b = function() {
            return "ok";
        };
        
        yield Route::get("/filters", [$a, $b]);
        $response = yield $http->request(new Request("http://127.0.0.1:8000/filters"));
        $this->assertEquals("text/html", $response->getHeader("Content-Type"));
    }

    private function testController(HttpClient $http) {
        $routes = Route::getAllRoutes();

        $helloExpectedContentType = Route::findProduces('GET', '/', 0)->getContentType()[0] ?? '';
        $this->assertEquals('text/plain', $helloExpectedContentType);


        $helloUsernameExpectedContentType = Route::findProduces('GET', '/{username}', 0)->getContentType()[0] ?? '';
        $this->assertEquals('text/html', $helloUsernameExpectedContentType);



        $this->assertArrayHasKey('GET', $routes);

        $this->assertArrayHasKey('/', $routes['GET']           ?? []);
        $this->assertArrayHasKey('/{username}', $routes['GET'] ?? []);


        /** @var Response $response1 */
        $response1 = yield $http->request(new Request("http://127.0.0.1:8000/", "GET"));
        $this->assertEquals("hello", yield $response1->getBody()->buffer());
        $this->assertEquals("text/plain", $response1->getHeader("Content-Type"));

        /** @var Response $response2 */
        $response2 = yield $http->request(new Request("http://127.0.0.1:8000/world", "GET"));
        $this->assertEquals("hello world", yield $response2->getBody()->buffer());
        $this->assertEquals("text/html", $response2->getHeader("Content-Type"));

        $request = new Request("http://127.0.0.1:8000/test", "GET");
        $request->addHeader("Authorization", "bearer 123");
        /** @var Response $response4 */
        $response3 = yield $http->request($request);
        $text1     = yield $response3->getBody()->buffer();
        $this->assertEquals("ok", $text1);
                
        /** @var Response $response4 */
        $response4 = yield $http->request(new Request("http://127.0.0.1:8000/test", "GET"));
        $text2     = yield $response4->getBody()->buffer();
        $this->assertEquals("unauthorized", $text2);
    }

    private function testOpenAPI(HttpClient $http, OpenAPIService $api) {
        /** @var Response $response */
        $response = yield $http->request(new Request("http://127.0.0.1:8000/openapi", "GET"));
        $text     = yield $response->getBody()->buffer();
        $json     = \json_decode($text, true);
        $this->assertNotEmpty($text);
        $this->assertArrayHasKey('openapi', $json);
        $this->assertArrayHasKey('info', $json);
        $this->assertArrayHasKey('paths', $json);
        $this->assertArrayHasKey('/{username}', $json['paths']);
        $this->assertArrayHasKey('get', $json['paths']['/{username}']);
    }
}

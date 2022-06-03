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

class ControllerTest extends AsyncTestCase {
    public function testPathAttributeAndDependencies() {
        $http = HttpClientBuilder::buildDefault();
        yield WebServer::stop();
        Route::clearAll();
        yield WebServer::start(interfaces: "127.0.0.1:8000");
        yield Container::load(dirname(__FILE__).'/../../composer.json');
        yield Container::run(function() use ($http) {
            echo Route::describe().PHP_EOL;

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
        });
        Loop::stop();
    }
}
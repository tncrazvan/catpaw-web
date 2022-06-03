<?php
namespace Tests\Controller;

use Amp\Http\Client\HttpClientBuilder;
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
        });
        Loop::stop();
    }
}
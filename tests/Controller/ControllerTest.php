<?php
namespace Tests\Controller;

use Amp\Loop;
use Amp\PHPUnit\AsyncTestCase;
use CatPaw\Utilities\Container;
use CatPaw\Web\Utilities\Route;
use Tests\Controller\SampleController;

class ControllerTest extends AsyncTestCase {
    public function testPathAttribute() {
        Route::clearAll();
        yield Container::load(dirname(__FILE__).'/../../composer.json');
        yield Container::run(function() {
            $routes = Route::getAllRoutes();
            $this->assertArrayHasKey('GET', $routes);
            $this->assertArrayNotHasKey('POST', $routes);
            $this->assertArrayHasKey('/', $routes['GET'] ?? []);
        });
        Loop::stop();
    }
}
<?php

namespace {

	use CatPaw\Attribute\StartWebServer;
	use CatPaw\Utility\Helpers\Route;
	use function Examples\Modules\test;

	#[StartWebServer]
	function main() {
		Route::get("/",function(){
			return test();
		});
	}
}
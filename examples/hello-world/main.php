<?php

namespace {


	use CatPaw\Web\Attribute\StartWebServer;
	use CatPaw\Web\Utility\Route;

	#[StartWebServer]
	function main() {
		Route::get("/", fn() => "hello world");
	}
}
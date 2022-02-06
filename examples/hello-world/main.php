<?php

namespace {

	use CatPaw\Attribute\StartWebServer;
	use CatPaw\Utility\Helpers\Route;

	#[StartWebServer]
	function main() {
		Route::get("/", fn() => "hello world");
	}
}
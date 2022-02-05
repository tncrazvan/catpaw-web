<?php

namespace {

	use CatPaw\Attributes\StartWebServer;
	use CatPaw\Tools\Helpers\Route;

	#[StartWebServer]
	function main() {
		Route::get("/plain", fn() => "this is plain text.");
		echo Route::describe();
	}
}
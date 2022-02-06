<?php

namespace {


	use CatPaw\Web\Attribute\StartWebServer;
	use CatPaw\Web\Utility\Route;

	#[StartWebServer]
	function main() {
		Route::get("@404", function() {
			return "Sorry, couldn't find the resource!";
		});
	}
}
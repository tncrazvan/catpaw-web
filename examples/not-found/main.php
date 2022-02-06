<?php

namespace {

	use CatPaw\Attribute\StartWebServer;
	use CatPaw\Utility\Helpers\Route;

	#[StartWebServer]
	function main() {
		Route::get("@404", function() {
			return "Sorry, couldn't find the resource!";
		});
	}
}
<?php

namespace {


	use CatPaw\Web\Attribute\Http\Produces;
	use CatPaw\Web\Attribute\Http\RequestBody;
	use CatPaw\Web\Attribute\Http\StartWebServer;
	use CatPaw\Web\Utility\Route;

	#[StartWebServer]
	function main() {

		$cats = [];

		Route::get(
			path    : "/cats",
			callback: #[Produces("application/json")]
			function() use (&$cats) {
				return $cats;
			}
		);

		Route::post(
			path    : "/cats",
			callback: #[Consumes("application/json")]
			function(
				#[RequestBody]
				array $cat
			) use (&$cats) {
				$cats[] = $cat;
			}
		);

	}
}

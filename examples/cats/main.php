<?php

namespace {


	use CatPaw\Web\Attribute\Produces;
	use CatPaw\Web\Attribute\RequestBody;
	use CatPaw\Web\Attribute\StartWebServer;
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

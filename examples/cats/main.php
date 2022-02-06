<?php

namespace {

	use CatPaw\Attribute\Http\Consumes;
	use CatPaw\Attribute\Http\Produces;
	use CatPaw\Attribute\Http\RequestBody;
	use CatPaw\Attribute\StartWebServer;
	use CatPaw\Utility\Helpers\Route;

	#[StartWebServer]
	function main(){

		$cats = [];

		Route::get(
			path    : "/cats",
			callback:
			#[Produces("application/json")]
			function() use ($cats) {
				return $cats;
			}
		);

		Route::post(
			path    : "/cats",
			callback:
			#[Consumes("application/json")]
			function(
				#[RequestBody]
				array $cat
			) use(&$cats) {
				$cats[] = $cat;
			}
		);

	}
}

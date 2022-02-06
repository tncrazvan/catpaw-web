<?php

namespace {

	use Amp\Http\Server\Response;
	use Amp\Http\Status;
	use CatPaw\Web\Attribute\PathParam;
	use CatPaw\Web\Attribute\StartWebServer;
	use CatPaw\Web\Utility\Route;


	#[StartWebServer]
	function main() {

		$filter1 = fn(#[PathParam] int $value) => $value > 0 ? true : new Response(Status::BAD_REQUEST, [], "Bad request :/");

		Route::get(
			path    : "/{value}",
			callback: [
						  $filter1,
						  fn(#[PathParam] int $value) => $value,
					  ]
		);
	}
}

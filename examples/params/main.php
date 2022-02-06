<?php

namespace {

	use CatPaw\Web\Attribute\PathParam;
	use CatPaw\Web\Attribute\StartWebServer;
	use CatPaw\Web\Utility\Route;

	#[StartWebServer]
	function main() {
		Route::get("/account/{username}", function(
			#[PathParam]
			string $username
		) {
			return "hello $username.";
		});

		Route::get("/account/{username}/active/{active}",function(
			#[PathParam]
			string $username,
			#[PathParam]
			bool $active
		){
			if($active)
				return "Account $username has been activated.";
			return "Account $username has been deactivated.";
		});

		Route::get("/account/{username}/{page}",function(
			#[PathParam]
			string $username,
			#[PathParam]
			string $page
		){
			return "hello $username, you are looking at page $page.";
		});

	}
}

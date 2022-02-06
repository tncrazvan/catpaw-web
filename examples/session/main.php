<?php

namespace {


	use CatPaw\Web\Attribute\Http\Produces;
	use CatPaw\Web\Attribute\Http\Session;
	use CatPaw\Web\Attribute\Http\StartWebServer;
	use CatPaw\Web\Utility\Route;

	#[StartWebServer]
	function main() {
		Route::get("/",
			#[Produces("text/html")]
			function(
				 #[Session]
				array &$session,
			) {
				if(!isset($session['created']))
					$session['created'] = time();

				$contents = print_r($session, true);

				return <<<HTML
					this is my session <br /><pre>$contents</pre>
				HTML;
			}
		);
	}
}
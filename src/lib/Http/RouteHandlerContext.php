<?php

namespace CatPaw\Web\Http;

class RouteHandlerContext {
	public string $method;
	public string $path;
	public bool   $isFilter;
}
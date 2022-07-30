<?php

namespace CatPaw\Web;

use Amp\ByteStream\IteratorStream;
use function Amp\call;
use function Amp\File\exists;
use Amp\File\File;
use function Amp\File\getSize;
use function Amp\File\isDirectory;
use function Amp\File\openFile;
use Amp\Http\Server\Request;

use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\LazyPromise;
use Amp\Producer;
use Amp\Promise;
use function CatPaw\isAssoc;
use CatPaw\Web\Attributes\RequestHeader;
use CatPaw\Web\Exceptions\InvalidByteRangeQueryException;
use CatPaw\Web\Interfaces\ByteRangeWriterInterface;
use CatPaw\Web\Services\ByteRangeService;
use CatPaw\Web\Utilities\Lazy;
use CatPaw\Web\Utilities\Mime;
use Closure;
use Throwable;


function markdown(HttpConfiguration $config, string $filename): Promise {
    return call(function() use ($config, $filename) {
        //##############################################################
        $filenameLower = strtolower($config->httpWebroot.$filename);
        if (!str_ends_with($filenameLower, ".md")) {
            return $config->httpWebroot.$filename;
        }
        //##############################################################

        $filenameMD = "./.cache/markdown$filename.html";
        $filename   = $config->httpWebroot.$filename;

        if (is_file($filenameMD)) {
            return $filenameMD;
        }


        if (!is_dir($dirnameMD = dirname($filenameMD))) {
            mkdir($dirnameMD, 0777, true);
        }

        /** @var File $html */
        $html = yield openFile($filename, "r");

        $unsafe = !str_ends_with($filenameLower, ".unsafe.md");

        $chunkSize = 65536;
        $contents  = '';

        while (!$html->eof()) {
            $chunk = yield $html->read($chunkSize);
            $contents .= $chunk;
        }
        yield $html->close();
        /** @var File $md */
        $md = yield openFile($filenameMD, "w");

        $config->mdp->setSafeMode($unsafe);
        $parsed = $config->mdp->parse($contents);

        yield $md->write($parsed);
        yield $md->close();

        return $filenameMD;
    });
}



function notfound(HttpConfiguration $config): Closure {
    $MARKDOWN = 0;
    $HTML     = 1;
    $OTHER    = 2;

    return function(
        #[RequestHeader("range")] false | array $range,
        Request $request,
        ByteRangeService $service,
    ) use ($config, $MARKDOWN, $HTML, $OTHER) {
        $path     = urldecode($request->getUri()->getPath());
        $filename = $config->httpWebroot.$path;
        
        if (yield isDirectory($filename)) {
            if (!str_ends_with($filename, '/')) {
                return new Response(Status::MOVED_PERMANENTLY, [
                    "Location" => "$path/"
                ]);
            }

            if (yield exists("{$filename}index.md")) {
                $filename .= 'index.md';
            } else {
                $filename .= 'index.html';
            }
        }


        $lowered = strtolower($filename);

        if (str_ends_with($lowered, '.md')) {
            $type = $MARKDOWN;
        } elseif (str_ends_with($lowered, '.html') || str_ends_with($lowered, ".htm")) {
            $type = $HTML;
        } else {
            $type = $OTHER;
        }

        if (!strpos($filename, '../') && (yield exists($filename))) {
            if ($MARKDOWN === $type) {
                /** @var string $filename */
                $filename = yield markdown($config, $filename);
            }
            $length = yield getSize($filename);
            try {
                return cached($config, $service->response(
                    rangeQuery: $range[0] ?? "",
                    headers   : [
                        "Content-Type"   => Mime::getContentType($filename),
                        "Content-Length" => $length,
                    ],
                    writer    : new class($filename) implements ByteRangeWriterInterface {
                        private File $file;

                        public function __construct(private string $filename) {
                        }

                        public function start(): Promise {
                            return call(function() {
                                $this->file = yield openFile($this->filename, "r");
                            });
                        }


                        public function data(callable $emit, int $start, int $length): Promise {
                            return call(function() use ($emit, $start, $length) {
                                yield $this->file->seek($start);
                                $data = yield $this->file->read($length);
                                yield $emit($data);
                            });
                        }


                        public function end(): Promise {
                            return new LazyPromise(function() {
                                yield $this->file->close();
                            });
                        }
                    }
                ));
            } catch (InvalidByteRangeQueryException) {
                return cached($config, new Response(
                    code          : Status::OK,
                    headers       : [
                        "Accept-Ranges"  => "bytes",
                        "Content-Type"   => Mime::getContentType($filename),
                        "Content-Length" => $length,
                    ],
                    stringOrStream: new IteratorStream(
                        new Producer(function($emit) use ($filename) {
                            /** @var File $file */
                            $file = yield openFile($filename, "r");
                            while ($chunk = yield $file->read(65536)) {
                                yield $emit($chunk);
                            }
                            yield $file->close();
                        })
                    )
                ));
            }
        }
        return cached($config, new Response(
            Status::NOT_FOUND,
            [],
            ''
        ));
    };
}

/**
 * @throws Throwable
 */
function cached(HttpConfiguration $config, Response $response): Response {
    $headers = [];
    foreach ($config->defaultCacheHeaders() as $key => $value) {
        $headers[$key] = $value;
    }

    foreach ($config->headers as $key => $value) {
        $headers[$key] = $value;
    }

    $response->setHeaders($headers);
    return $response;
}



function lazy(callable $id, array &$props):array {
    $state = [];

    foreach ($props as $key => $defaultValue) {
        if (
            null === $defaultValue
            || \is_string($defaultValue) 
            || \is_numeric($defaultValue) 
            || \is_bool($defaultValue)
            || (
                is_array($defaultValue) 
                && !isAssoc($defaultValue)
            )
        ) {
            $lazyValue = new Lazy(
                id: $id($key),
                get: function() use (&$props, $key) {
                    return $props[$key];
                },
                set: function($newValue) use (&$props, $key) {
                    $props[$key] = $newValue;
                }
            );
            $lazyValue->publish();
            $lazyValue->bind($props[$key]);
            $state[$key] = $lazyValue->build();
            continue;
        }
        $state[$key] = lazy(fn($key2) => $id("$key.$key2"), $props[$key]);
    }

    return $state;
}
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
use CatPaw\Web\Attributes\RequestHeader;
use CatPaw\Web\Exceptions\InvalidByteRangeQueryException;
use CatPaw\Web\Interfaces\ByteRangeWriterInterface;
use CatPaw\Web\Services\ByteRangeService;
use CatPaw\Web\Utilities\Mime;
use Closure;

function notfound(HttpConfiguration $config): Closure {
    return function (
        #[RequestHeader("range")] false | array $range,
        Request $request,
        ByteRangeService $service,
    ) use ($config) {
        $path = urldecode($request->getUri()->getPath());
        $filename = $config->httpWebroot.$path;
        if (yield isDirectory($filename)) {
            if (!str_ends_with($filename, '/')) {
                $filename .= '/';
            }

            if (yield exists("{$filename}index.md")) {
                $filename .= 'index.md';
            } else {
                $filename .= 'index.html';
            }
        }

        $lowered = strtolower($filename);

        if (str_ends_with($lowered, '.md')) {
            $type = self::MARKDOWN;
        } elseif (str_ends_with($lowered, '.html') || str_ends_with($lowered, ".htm")) {
            $type = self::HTML;
        } else {
            $type = self::OTHER;
        }

        if (!strpos($filename, '../') && (yield exists($filename))) {
            if (self::MARKDOWN === $type) {
                /** @var string $filename */
                $filename = yield self::markdown($config, $filename);
            }
            $length = yield getSize($filename);
            try {
                return cached($config, $service->response(
                    rangeQuery: $range[0] ?? "",
                    headers: [
                        "Content-Type" => Mime::getContentType($filename),
                        "Content-Length" => $length,
                    ],
                    writer: new class($filename) implements ByteRangeWriterInterface {
                        private File $file;

                        public function __construct(private string $filename) {
                        }

                        public function start(): Promise {
                            return call(function () {
                                $this->file = yield openFile($this->filename, "r");
                            });
                        }


                        public function data(callable $emit, int $start, int $length): Promise {
                            return call(function () use ($emit, $start, $length) {
                                yield $this->file->seek($start);
                                $data = yield $this->file->read($length);
                                yield $emit($data);
                            });
                        }


                        public function end(): Promise {
                            return new LazyPromise(function () {
                                yield $this->file->close();
                            });
                        }
                    }
                ));
            } catch (InvalidByteRangeQueryException) {
                return cached($config, new Response(
                    code: Status::OK,
                    headers: [
                        "accept-ranges" => "bytes",
                        "Content-Type" => Mime::getContentType($filename),
                        "Content-Length" => $length,
                    ],
                    stringOrStream: new IteratorStream(
                        new Producer(function ($emit) use ($filename) {
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

function cached(HttpConfiguration $config, Response $response): Response {
    $response->setHeaders([$config->defaultCacheHeaders(),$config->headers]);
    return $response;
}

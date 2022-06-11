<?php

namespace CatPaw\Web\Interfaces;

interface ByteRangeWriterInterface {
    public function start();

    /**
     * @param callable $emit
     * @param int      $start
     * @param int      $length
     */
    public function data(callable $emit, int $start, int $length);
    
    public function end();
}

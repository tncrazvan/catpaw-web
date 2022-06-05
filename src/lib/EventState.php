<?php

namespace CatPaw\Web;

class EventState {
    public function __construct(private array $value) {
    }

    public function set(array $value):void {
        $this->value = $value;
    }

    public function get():array {
        return $this->value;
    }
}
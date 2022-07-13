<?php

namespace App\Classes;

class PairTypedValues{
    public string $main;
    public array $keywords;
    public $value;

    public function __construct(string $main, array $keywords, $value = null)
    {
        $this->main = $main;
        $this->keywords = $keywords;
        $this->value = $value;
    }

}
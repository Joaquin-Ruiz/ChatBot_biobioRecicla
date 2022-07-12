<?php

namespace App\Classes;

use App\Classes\BotResponse;

class EmptyResponse extends BotResponse{

    public function __construct()
    {
        parent::__construct('');
    }
}

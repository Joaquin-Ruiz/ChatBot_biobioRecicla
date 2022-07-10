<?php

namespace App\Classes;

use Closure;

class ChatButton{
    public $text;
    public $additionalKeywords;

    public $botResponse;

    /**
     * Should return a bot response
     * @var Closure
     */
    public $createBotResponse;

    /**
     * Called on button pressed
     * @var Closure
     */
    public $onPressed;

    public function __construct(
        string $text, 
        Closure $createBotResponse, 
        array $additionalKeywords = [],
        ?Closure $onPressed = null
    )
    {
        $this->text = $text;
        $this->createBotResponse = $createBotResponse;
        $this->onPressed = $onPressed;
        $this->additionalKeywords = $additionalKeywords;
    }
}
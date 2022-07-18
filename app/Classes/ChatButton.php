<?php

namespace App\Classes;

use Closure;

class ChatButton{
    public $text;
    public $additionalKeywords;

    public $visible = true;
    public $enabled = true;

    public $botResponse;

    /**
     * Should return a bot response
     */
    public $createBotResponse;

    /**
     * Called on button pressed
     * @var Closure
     */
    public $onPressed;

    public function __construct(
        string $text, 
        $createBotResponse, 
        array $additionalKeywords = [],
        ?Closure $onPressed = null,
        bool $visible = true,
        bool $enabled = true
    )
    {
        $this->text = $text;
        $this->createBotResponse = $createBotResponse;
        $this->onPressed = $onPressed;
        $this->additionalKeywords = $additionalKeywords;
        $this->enabled = $enabled;
        $this->visible = $visible;
    }
}
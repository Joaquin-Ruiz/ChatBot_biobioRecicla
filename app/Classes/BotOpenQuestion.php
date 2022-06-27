<?php

namespace App\Classes;

use App\Classes\BotResponse;
use Closure;
use Opis\Closure\SerializableClosure;

class BotOpenQuestion extends BotResponse{
    /// SHOULD RETURN TRUE OR FALSE IF CAN CONTINUE
    public $validationCallback;

    /**
     * @var BotResponse
     */
    public $errorResponse;

    /**
     * @var bool
     */
    public $onErrorBackToRoot = false;

    public function __construct(
        string $text, 
        ?Closure $nextResponse = null, 
        ?Closure $validationCallback = null, 
        ?string $errorMessage = null, 
        ?BotResponse $errorResponse = null, 
        bool $onErrorBackToRoot = false,
        bool $saveLog = false,
        ?float $botTypingSeconds = null
    )
    {
        parent::__construct(
            $text,
            null,
            $saveLog,
            $nextResponse,
            false,
            null,
            [],
            $errorMessage,
            null,
            $botTypingSeconds
        );
        $this->errorResponse = $errorResponse;
        $this->onErrorBackToRoot = $onErrorBackToRoot;
        $this->validationCallback = $validationCallback ?? fn() => true;
    }
}

<?php

namespace App\Classes;

use BotMan\BotMan\Messages\Attachments\Attachment;
use Closure;
use Opis\Closure\SerializableClosure;

class BotResponse{          // Can be a question
    public $text;
    public $buttons;        // nullable
    public bool $displayButtons = true;
    public $saveLog;        // true - false / Save history

    /**
     * If true, so this bot response will be used as new root
     * @var bool
     */
    public bool $autoRoot = false;

    /**
     * @var ?BotResponse
     */
    public ?BotResponse $rootResponse = null;

    /**
     * Should return Bot Response
     * @var ?Closure
     */
    public $nextResponse = null;

    /**
     * @var string
     */
    public ?string $errorMessage = null;

    /**
     * @var Attachment
     */
    public ?Attachment $attachment;

    /**
     * @var array
     */
    public array $additionalParams = array();

    /**
     * @var ?float
     */
    public ?float $botTypingSeconds = null;

    /**
     * @var ?Closure
     */
    public $onExecute = null;

    public function __construct(
        $text, 
        ?array $buttons = null, 
        bool $saveLog = false, 
        ?Closure $nextResponse = null,
        bool $autoRoot = false,
        ?BotResponse $customRootResponse = null,
        array $additionalParams = [],
        string $errorMessage = null,
        ?Attachment $attachment = null,
        ?float $botTypingSeconds = null,
        bool $displayButtons = true,
        ?Closure $onExecute = null
    )
    {
        $this->text = $text;
        $this->saveLog = $saveLog;
        $this->buttons = $buttons;
        $this->nextResponse = $nextResponse;
        $this->rootResponse = $customRootResponse;
        $this->autoRoot = $autoRoot;
        $this->additionalParams = $additionalParams;
        $this->errorMessage = $errorMessage;
        $this->attachment = $attachment;
        $this->botTypingSeconds = $botTypingSeconds;
        $this->onExecute = $onExecute;
        $this->displayButtons = $displayButtons;
    }

}
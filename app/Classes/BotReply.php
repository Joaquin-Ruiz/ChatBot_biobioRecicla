<?php

namespace App\Classes;

use App\Classes\BotResponse;
use BotMan\BotMan\Messages\Attachments\Attachment;
use Closure;

class BotReply extends BotResponse{

    public function __construct(
        $text,
        ?Closure $nextResponse = null,
        array $additionalParams = [],
        ?Attachment $attachment = null,
        bool $saveLog = false,
        ?float $botTypingSeconds = null,
        ?Closure $onExecute = null
    )
    {
        parent::__construct(
            $text,
            null,
            $saveLog,
            $nextResponse,
            false,
            null,
            $additionalParams,
            null,
            $attachment,
            $botTypingSeconds,
            false,
            $onExecute
        );
    }
}

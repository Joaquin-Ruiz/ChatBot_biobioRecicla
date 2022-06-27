<?php

namespace App\Classes;

use App\Classes\BotResponse;
use App\Classes\BotReply;

class ChatFlowParser{

    public static function jsonToChatFlow(string $jsonText) : ?BotResponse {
        $jsonObject = json_decode($jsonText);
        return ChatFlowParser::jsonObjectToResponse($jsonObject->responses[0]);
    }

    public static function jsonObjectToResponse($jsonObject) {
        if($jsonObject == null) return null;

        // Detect type
        $type = $jsonObject->type;
        if($type == 'BotReply'){
            $nextResponse = ChatFlowParser::jsonObjectToResponse($jsonObject->nextResponse);

            return new BotReply(
                $jsonObject->text,
                $nextResponse != null? 
                    fn() => ChatFlowParser::jsonObjectToResponse($jsonObject->nextResponse) : null
            );
        } else if($type == 'BotResponse'){
            return new BotResponse(
                $jsonObject->text
            );
        }

        return null;
    }

}
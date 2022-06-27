<?php

namespace App\Classes;

use App\Classes\BotResponse;
use App\Classes\BotReply;
use BotMan\BotMan\Messages\Incoming\Answer;
use Illuminate\Support\Facades\Storage;

class ChatFlowParser{

    public static function getRootFromJsonToChatFlow(string $jsonText) : ?BotResponse{
        $jsonObject = json_decode($jsonText);
        if(isset($jsonObject->responses->root))
            return ChatFlowParser::jsonObjectToResponse($jsonObject->responses->root, $jsonObject->responses);
        return null;
    }

    public static function jsonToChatFlow(string $jsonText) : ?BotResponse {
        $jsonObject = json_decode($jsonText);
        return ChatFlowParser::jsonObjectToResponse($jsonObject->responses->start, $jsonObject->responses);
    }

    public static function jsonObjectToResponse($jsonObject, $responsesList) {
        if($jsonObject == null) return null;
        
        if(gettype($jsonObject) == "string"){
            $arrayResponses = json_decode(json_encode($responsesList), true);
            return ChatFlowParser::jsonObjectToResponse(json_decode(json_encode($arrayResponses[$jsonObject])), $responsesList); 
        }

        // Detect type
        if(!isset($jsonObject->type)) return null;
        $type = $jsonObject->type;

        // Next response
        $nextResponse = null;
        if(isset($jsonObject->nextResponse))
                $nextResponse = ChatFlowParser::jsonObjectToResponse($jsonObject->nextResponse, $responsesList);

        // Save log
        $saveLog = false;
        if(isset($jsonObject->saveLog)) $saveLog = $jsonObject->saveLog;

        // Return response according to type
        if($type == 'BotReply'){
            return new BotReply(
                $jsonObject->text,
                $nextResponse != null? (fn() => $nextResponse) : null
            );
        } else if($type == 'BotResponse'){
            $buttons = null;
            if(isset($jsonObject->buttons)){
                $buttons = array_map(fn($item) => ChatFlowParser::jsonToChatButton($item, $responsesList), $jsonObject->buttons);
            }
            
            return new BotResponse(
                $jsonObject->text,
                $buttons,
                false,
                $nextResponse != null? (fn() => $nextResponse) : null
            );
        } else if($type == 'BotOpenQuestion'){
            $validationRegex = null;
            $validationFunction = null;
            if(isset($jsonObject->validationRegex)) {
                $validationRegex = $jsonObject->validationRegex;
                if($validationRegex == 'phone'){
                    $validationFunction = fn(Answer $answer) => preg_match(ConversationFlow::phone_regex(), $answer);
                }
                else if($validationRegex == 'email'){
                    $validationFunction = fn(Answer $answer) => preg_match(ConversationFlow::email_regex(), $answer);
                } else $validationFunction = fn(Answer $answer) => preg_match($validationRegex, $answer);
            }

            return new BotOpenQuestion(
                $jsonObject->text,
                $nextResponse != null? (fn() => $nextResponse) : null,
                $validationFunction,
                $jsonObject->errorMessage ?? null,
                fn() => true
            );
        }

        return null;
    }

    public static function jsonToChatButton($jsonObject, $responsesList){
        if($jsonObject == null) return null;

        $nextResponse = ChatFlowParser::jsonObjectToResponse($jsonObject->nextResponse, $responsesList);
        if($nextResponse == null) return null;

        return new ChatButton(
            $jsonObject->text,
            fn() => $nextResponse
        );
    }

}
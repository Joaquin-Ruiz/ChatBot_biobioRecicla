<?php

namespace App\Classes;

use App\Classes\BotResponse;
use App\Classes\BotReply;
use App\Conversations\BaseFlowConversation;
use BotMan\BotMan\Messages\Attachments\Audio;
use BotMan\BotMan\Messages\Attachments\File;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Attachments\Location;
use BotMan\BotMan\Messages\Attachments\Video;
use BotMan\BotMan\Messages\Incoming\Answer;
use Illuminate\Support\Facades\Storage;

class ChatFlowParser{

    public static function getRootFromJsonToChatFlow($context, string $jsonText) : ?BotResponse{
        $jsonObject = json_decode($jsonText);
        if(isset($jsonObject->responses->root))
            return ChatFlowParser::jsonObjectToResponse($context, $jsonObject->responses->root, $jsonObject->responses);
        return null;
    }

    public static function jsonToChatFlow(BaseFlowConversation $context, string $jsonText) : ?BotResponse {
        $jsonObject = json_decode($jsonText);
        return ChatFlowParser::jsonObjectToResponse($context, $jsonObject->responses->start, $jsonObject->responses);
    }

    public static function jsonObjectToResponse(BaseFlowConversation $context, $jsonObject, $responsesList) {
        if($jsonObject == null) return null;
        
        if(gettype($jsonObject) == "string"){
            $arrayResponses = json_decode(json_encode($responsesList), true);
            return ChatFlowParser::jsonObjectToResponse($context, json_decode(json_encode($arrayResponses[$jsonObject])), $responsesList); 
        }

        // Detect type
        if(!isset($jsonObject->type)) return null;
        $type = $jsonObject->type;

        // Next response
        $nextResponse = null;
        if(isset($jsonObject->nextResponse))
                $nextResponse = ChatFlowParser::jsonObjectToResponse($context, $jsonObject->nextResponse, $responsesList);

        // Save log
        $saveLog = false;
        if(isset($jsonObject->saveLog)) $saveLog = $jsonObject->saveLog;

        // Try save contact data
        $trySaveContactData = null;
        if(isset($jsonObject->trySaveContactData) && $jsonObject->trySaveContactData) $trySaveContactData = function() use ($context) {
            if(
                !(array_key_exists('name', $context->savedKeys) && 
                array_key_exists('phone', $context->savedKeys) && 
                array_key_exists('email', $context->savedKeys))
            ) return;

            $context->getConversationFlow()->update_contact(
                $context->savedKeys['name'],
                $context->savedKeys['phone'],
                $context->savedKeys['email'],
                true
            );
        };

        // Bot typing effect
        $botTypingSeconds = null;
        if(isset($jsonObject->botTypingSeconds)) $botTypingSeconds = $jsonObject->botTypingSeconds;

        // Additional parameters
        $additionalParameters = [];
        if(isset($jsonObject->additionalParameters)){
            $additionalParameters = json_decode(json_encode($jsonObject->additionalParameters), true);
        }

        // Auto root
        $autoRoot = false;
        if(isset($jsonObject->autoRoot)) $autoRoot = $jsonObject->autoRoot;

        // Attachment parameters
        $attachment = null;
        if(isset($jsonObject->attachment)){
            $attachmentObject = $jsonObject->attachment;
            
            if($attachmentObject->type == 'Image'){
                $attachment = new Image($attachmentObject->url);
            } else if($attachmentObject->type == 'Video'){
                $attachment = new Video($attachmentObject->url);
            } else if($attachmentObject->type == 'Audio'){
                $attachment = new Audio($attachmentObject->url);
            } else if($attachmentObject->type == 'File'){
                $attachment = new File($attachmentObject->url);
            } else if($attachmentObject->type == 'Location'){
                $attachment = new Location($attachmentObject->latitude, $attachmentObject->longitude);
            }
        }

        // Return response according to type
        if($type == 'BotReply'){
            return new BotReply(
                $jsonObject->text,
                $nextResponse != null? (fn() => $nextResponse) : null,
                $additionalParameters,
                $attachment,
                $saveLog,
                $botTypingSeconds
            );
        } else if($type == 'BotResponse'){
            $buttons = null;
            if(isset($jsonObject->buttons)){
                $buttons = array_map(fn($item) => ChatFlowParser::jsonToChatButton($context, $item, $responsesList), $jsonObject->buttons);
            }
            
            return new BotResponse(
                $jsonObject->text,
                $buttons,
                $saveLog,
                $nextResponse != null? (fn() => $nextResponse) : null,
                $autoRoot,
                null,
                $additionalParameters,
                null,
                $attachment,
                $botTypingSeconds
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

            $saveKeyFunction = null;
            if(isset($jsonObject->saveAnswerKey)){
                $saveKeyFunction = fn(Answer $answer) => $context->savedKeys[$jsonObject->saveAnswerKey] = $answer->getText();
            }

            $onValidatedAnswer = function(Answer $answer) use ($saveKeyFunction, $trySaveContactData){
                if($saveKeyFunction != null) $saveKeyFunction($answer);
                if($trySaveContactData) $trySaveContactData();
            };

            return new BotOpenQuestion(
                $jsonObject->text,
                $nextResponse != null? (fn() => $nextResponse) : null,
                $validationFunction,
                $jsonObject->errorMessage ?? null,
                fn($answer) => $onValidatedAnswer($answer),
                null,
                null,
                $autoRoot,
                $saveLog,
                $botTypingSeconds
            );
        }

        return null;
    }

    public static function jsonToChatButton($context, $jsonObject, $responsesList){
        if($jsonObject == null) return null;

        $nextResponse = ChatFlowParser::jsonObjectToResponse($context, $jsonObject->nextResponse, $responsesList);
        if($nextResponse == null) return null;

        return new ChatButton(
            $jsonObject->text,
            fn() => $nextResponse
        );
    }

}
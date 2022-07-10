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
        ChatFlowParser::saveVariables($context, $jsonObject->variables);
        return ChatFlowParser::jsonObjectToResponse($context, $jsonObject->responses->start, $jsonObject->responses);
    }

    public static function jsonObjectToResponse(BaseFlowConversation $context, $jsonObject, $responsesList) {
        if($jsonObject == null) return null;
        
        if(gettype($jsonObject) == "string"){
            $arrayResponses = json_decode(json_encode($responsesList), true);

            if(!array_key_exists($jsonObject, $arrayResponses))
                return new BotReply(ChatFlowParser::replaceTextByVariables($context, $jsonObject));

            return ChatFlowParser::jsonObjectToResponse($context, json_decode(json_encode($arrayResponses[$jsonObject])), $responsesList); 
        }

        // Detect type
        if(!isset($jsonObject->type)) $type = 'BotResponse';
        else $type = $jsonObject->type;

        // Next response
        $nextResponse = null;
        if(isset($jsonObject->nextResponse))
                $nextResponse = fn() => ChatFlowParser::jsonObjectToResponse($context, $jsonObject->nextResponse, $responsesList);

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

        // Get Json Object Text
        $responseText = '';
        if(gettype($jsonObject->text) == 'array'){
            $responseText = array();
            foreach($jsonObject->text as $itemText){
                array_push($responseText, ChatFlowParser::replaceTextByVariables($context, $itemText));
            }
            
        } else $responseText = ChatFlowParser::replaceTextByVariables($context, $jsonObject->text);
        

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
                $responseText,
                $nextResponse,
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
                $responseText,
                $buttons,
                $saveLog,
                $nextResponse,
                $autoRoot,
                null,
                $additionalParameters,
                null,
                $attachment,
                $botTypingSeconds,
                $jsonObject->displayButtons ?? true
            );
        } else if($type == 'Question'){
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
            if(isset($jsonObject->saveKey)){
                $saveKeyFunction = function($answer) use ($context, $jsonObject) {
                    Storage::disk('public')->put('test.txt', 'SAVED'.$jsonObject->saveKey.' - '.$answer);
                    $context->savedKeys[$jsonObject->saveKey] = $answer;
                };
            }

            $onValidatedAnswer = function($answer) use ($saveKeyFunction, $trySaveContactData){
                if($saveKeyFunction != null) $saveKeyFunction($answer);
                if($trySaveContactData != null) $trySaveContactData();
            };

            return new BotOpenQuestion(
                $responseText,
                $nextResponse,
                $validationFunction,
                $jsonObject->errorMessage ?? null,
                fn($answer) => $onValidatedAnswer($answer),
                null,
                null,
                $autoRoot,
                $saveLog,
                $botTypingSeconds,
                $jsonObject->processAnswer ?? false,
                $jsonObject->learningArray ?? []
            );
        }

        return null;
    }

    public static function saveVariables($context, $variablesSection){
        foreach($variablesSection as $itemKey => $itemValue){
            $context->savedKeys[$itemKey] = $itemValue;
        }
    }

    public static function jsonToChatButton($context, $jsonObject, $responsesList){
        if($jsonObject == null) return null;

        $nextResponse = fn() => ChatFlowParser::jsonObjectToResponse($context, $jsonObject->nextResponse, $responsesList);

        if(($nextResponse)() == null) return null;

        $keyWordsToUse = array();
        if(isset($jsonObject->keywords)){
            foreach($jsonObject->keywords as $keyword){
                array_push($keyWordsToUse, ChatFlowParser::replaceTextByVariables($context, $keyword));
            }
        }

        return new ChatButton(
            ChatFlowParser::replaceTextByVariables($context, $jsonObject->text),
            $nextResponse,
            $keyWordsToUse
        );
    }

    protected static function replaceTextByVariables(BaseFlowConversation $context, string $text){
        return preg_replace_callback('/\{[^}]*\}/', function($matches) use ($context){
            $word = end($matches);
            $variableName = substr(substr($word, 1, strlen($word)), 0, strlen($word) - 2);
            return $context->savedKeys[$variableName] ?? '';
        }, $text);
    }

}
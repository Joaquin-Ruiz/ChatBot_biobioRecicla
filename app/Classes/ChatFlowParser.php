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
use Exception;
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
        } else if(gettype($jsonObject) == "array"){
            $responseText = array();
            foreach($jsonObject as $itemText){
                array_push($responseText, ChatFlowParser::replaceTextByVariables($context, $itemText));
            }
            return new BotReply($responseText);
        }

        // Detect type
        if(!isset($jsonObject->type)) $type = 'BotResponse';
        else $type = $jsonObject->type;
        $type = strtolower($type);

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
        if(isset($jsonObject->text)){
            if(gettype($jsonObject->text) == 'array'){
                $responseText = array();
                foreach($jsonObject->text as $itemText){
                    array_push($responseText, ChatFlowParser::replaceTextByVariables($context, $itemText));
                }
                
            } else $responseText = ChatFlowParser::replaceTextByVariables($context, $jsonObject->text);
        }
        

        // Bot typing effect
        $botTypingSeconds = null;
        if(isset($jsonObject->botTypingSeconds)) $botTypingSeconds = $jsonObject->botTypingSeconds;

        // Additional parameters
        $additionalParameters = [];
        if(isset($jsonObject->additionalParameters)){
            if(gettype($jsonObject->additionalParameters) == 'string'){
                $additionalParameters = ChatFlowParser::getVariable($context, $jsonObject->additionalParameters);
            } else {
                $additionalParameters = json_decode(json_encode($jsonObject->additionalParameters), true);
                $additionalParameters = ChatFlowParser::replaceTextByVariablesOfArray($context, $additionalParameters);
            }
        }

        // Auto root
        $autoRoot = false;
        if(isset($jsonObject->autoRoot)) $autoRoot = $jsonObject->autoRoot;

        // Attachment parameters
        $attachment = null;
        if(isset($jsonObject->attachment)){
            $attachmentObject = $jsonObject->attachment;
            $attachmentUrl = ChatFlowParser::replaceTextByVariables($context, $attachmentObject->url);
            
            if($attachmentObject->type == 'Image'){
                $attachment = new Image($attachmentUrl);
            } else if($attachmentObject->type == 'Video'){
                $attachment = new Video($attachmentUrl);
            } else if($attachmentObject->type == 'Audio'){
                $attachment = new Audio($attachmentUrl);
            } else if($attachmentObject->type == 'File'){
                $attachment = new File($attachmentUrl);
            } else if($attachmentObject->type == 'Location'){
                $attachment = new Location($attachmentObject->latitude, $attachmentObject->longitude);
            }
        }

        // Return response according to type
        if($type == 'empty'){
            return new EmptyResponse();
        }
        else if($type == 'botreply'){
            return new BotReply(
                $responseText,
                $nextResponse,
                $additionalParameters,
                $attachment,
                $saveLog,
                $botTypingSeconds
            );
        } else if($type == 'botresponse'){
            $buttons = null;
            if(isset($jsonObject->buttons)){
                $saveKey = isset($jsonObject->saveKey)? $jsonObject->saveKey : null;
                $buttons = array_map(fn($item) => ChatFlowParser::jsonToChatButton($context, $item, $saveKey, $responsesList), $jsonObject->buttons);
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
        } else if($type == 'question'){
            $validationRegex = null;
            $validationFunction = null;
            if(isset($jsonObject->validationRegex)) {
                $validationRegex = $jsonObject->validationRegex;
                if($validationRegex == 'phone'){
                    $validationFunction = fn($answer) => preg_match(ConversationFlow::phone_regex(), $answer);
                }
                else if($validationRegex == 'email'){
                    $validationFunction = fn( $answer) => preg_match(ConversationFlow::email_regex(), $answer);
                } else $validationFunction = fn($answer) => preg_match($validationRegex, $answer);
            } else if(isset($jsonObject->validationRange)){
                $validationFunction = fn($answer) => count(array_filter(
                    $jsonObject->validationRange,
                    fn($eachValue) => mb_strtolower(ConversationFlow::remove_accents($eachValue)) == mb_strtolower(ConversationFlow::remove_accents($answer))
                )) > 0;
            }

            $saveKeyFunction = null;
            if(isset($jsonObject->saveKey)){
                $saveKeyFunction = function($answer) use ($context, $jsonObject) {
                    $context->savedKeys[$jsonObject->saveKey] = $answer;
                };
            }

            $onValidatedAnswer = function($answer) use ($saveKeyFunction, $trySaveContactData){
                if($saveKeyFunction != null) $saveKeyFunction($answer);
                if($trySaveContactData != null) $trySaveContactData();
            };

            $learningArray = [];
            if(isset($jsonObject->learningArray)){
                if(gettype($jsonObject->learningArray) == 'string') 
                    $learningArray = ChatFlowParser::getVariable($context, $jsonObject->learningArray);
                else $learningArray = json_decode(json_encode($jsonObject->learningArray), true);
            }

            $isMultiple = false;
            if(isset($jsonObject->isMultiple)) $isMultiple = $jsonObject->isMultiple;

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
                $learningArray,
                $isMultiple
            );
        } else if($type == 'function'){
            $functionName = $jsonObject->name;
            $functionName = strtolower($functionName);

            // Required map
            $map = null;
            if(isset($jsonObject->map)){
                $map = $jsonObject->map;
                $map = json_decode(json_encode($map), true);
                if(gettype($map) == 'string'){
                    $map = ChatFlowParser::getVariable($context, ChatFlowParser::replaceTextByVariables($context, $map));
                }
                
            }
            // Required array
            $array = null;
            if(isset($jsonObject->array)){
                $array = $jsonObject->array;
                $array = json_decode(json_encode($array), true);
                if(gettype($array) == 'string'){
                    $array = ChatFlowParser::getVariable($context, ChatFlowParser::replaceTextByVariables($context, $array));
                }
                
            }

            // Result variable
            $resultVariableName = isset($jsonObject->result)? $jsonObject->result : null;

            // Save Result
            $saveResultVariable = null;
            // Condition
            $condition = null;
            if(isset($jsonObject->condition))
                $condition = $jsonObject->condition;

            // Strict use
            $strict = false;
            if(isset($jsonObject->strict)) $strict = $jsonObject->strict;

            if($functionName == 'getfrommap'){
                // Required map
                // Required find
                $find = ChatFlowParser::replaceTextByVariables($context, $jsonObject->find);

                // Save result
                if(gettype($map) == 'array') $saveResultVariable = array_key_exists($find, $map)? $map[$find] : null;
                
            } else if($functionName == 'where'){
                
                $key = ChatFlowParser::replaceTextByVariables($context, $jsonObject->key);

                $or = false;
                if(isset($jsonObject->or)) $or = $jsonObject->or;

                $value = json_decode(json_encode($jsonObject->value), true);
                if(gettype($value) == 'string') {
                    $variableFromThis = ChatFlowParser::getVariableNameFromText($jsonObject->value);
                    if($variableFromThis == null) $value = ChatFlowParser::replaceTextByVariables($context, $jsonObject->value);
                    else $value = ChatFlowParser::getVariable($context, $variableFromThis);
                }
                if(gettype($value) == 'array') $value = ChatFlowParser::replaceTextByVariablesOfArray($context, $value);
                
                if(gettype($array) == 'array') {
                    $saveResultVariable = array_where($array, function($item) use($key, $value, $condition, $strict, $or) {
                        $item = json_decode(json_encode($item), true);
                        $itemValue = $item[$key];
                        $valueToCheck = $value;

                        // TODO: CURRENT ONLY WORKS FOR STRINGS

                        if(!$strict){
                            $itemValue = mb_strtolower(ConversationFlow::remove_accents($itemValue));
                            if(gettype($valueToCheck) == 'string')
                                $valueToCheck = mb_strtolower(ConversationFlow::remove_accents($valueToCheck));
                            else if(gettype($valueToCheck) == 'array')
                                $valueToCheck = array_map(fn($item) => mb_strtolower(ConversationFlow::remove_accents($item)), $valueToCheck);
                        }

                        $finalArrayToCheck = gettype($valueToCheck) == 'array'? $valueToCheck : [$valueToCheck];

                        $orFinalResult = false;

                        foreach($finalArrayToCheck as $eachToCheck){
                            if($or){
                                if($condition == 'equal') if($itemValue == $eachToCheck) $orFinalResult = true;
                                if($condition == 'not equal') if($itemValue != $eachToCheck) $orFinalResult = true;
                                if($condition == 'contains') if(str_contains($itemValue, $eachToCheck)) $orFinalResult = true;
                                if($condition == 'not contains') if(!str_contains($itemValue, $eachToCheck)) $orFinalResult = true;
                            }
                            else {
                                if($condition == 'equal') if($itemValue != $eachToCheck) return false;
                                if($condition == 'not equal') if($itemValue == $eachToCheck) return false;
                                if($condition == 'contains') if(!str_contains($itemValue, $eachToCheck)) return false;
                                if($condition == 'not contains') if(str_contains($itemValue, $eachToCheck)) return false;
                            }
                        }

                        if($or) return $orFinalResult;
                        
                        return true;
                    });
                }
            } else if($functionName == 'count'){
                $countable = $array ?? $map;
                if(!is_countable($countable)) $saveResultVariable = '0';
                else $saveResultVariable = count($array ?? $map);
            } else if($functionName == 'clear'){
                $variable = $jsonObject->variable;
                if(gettype($variable) == 'string'){
                    $variable = [$variable];
                }
                foreach($variable as $eachVariable){
                    ChatFlowParser::saveVariable($context, $eachVariable, null);
                }
            } else if($functionName == 'if'){

                $then = $jsonObject->then;
                $thenResponse = ChatFlowParser::jsonObjectToResponse($context, $then, $responsesList);

                $else = $jsonObject->else;
                $elseResponse = ChatFlowParser::jsonObjectToResponse($context, $else, $responsesList);
                
                if(isset($jsonObject->nextResponse) && $jsonObject->nextResponse != null)
                {
                    if($thenResponse instanceof BotResponse)
                        $thenResponse->nextResponse = fn() => ChatFlowParser::jsonObjectToResponse(
                            $context, 
                            $jsonObject->nextResponse, 
                            $responsesList
                        );
                    if($elseResponse instanceof BotResponse)
                        $elseResponse->nextResponse = fn() => ChatFlowParser::jsonObjectToResponse(
                            $context, 
                            $jsonObject->nextResponse, 
                            $responsesList
                        );
                }

                $result = ChatFlowParser::checkConditions(
                    $context, 
                    $condition, 
                    $jsonObject->itemA,
                    $jsonObject->itemB,
                    $strict
                );

                if($result) return $thenResponse ?? new EmptyResponse();
                else return $elseResponse ?? new EmptyResponse();                
                
            } else if($functionName == 'foreach'){
                /*
                $arrayToUse = $array ?? $map;

                if(gettype($arrayToUse) != 'array') throw new Exception('Is not array in foreach ChatFlow');

                $preItem = ChatFlowParser::getVariable($context, 'item');
                foreach($arrayToUse as $eachItem){
                    ChatFlowParser::saveVariable($context, 'item', $eachItem);


                }
                ChatFlowParser::saveVariable($context, 'item', $preItem);*/
            }

            //Save result if needed
            if($saveResultVariable != null) ChatFlowParser::saveVariable($context, $resultVariableName, $saveResultVariable);
            
            // Return next response
            if(isset($jsonObject->nextResponse) && $jsonObject->nextResponse != null)
                return ChatFlowParser::jsonObjectToResponse($context, $jsonObject->nextResponse, $responsesList);
        }

        return new EmptyResponse();
    }

    public static function checkConditions($context, $condition, $itemA, $itemB, $strict){

        if(gettype($itemA) == 'string')
            $itemA = ChatFlowParser::replaceTextByVariables($context, $itemA);
        if(gettype($itemA) == 'array')
            $itemA = ChatFlowParser::replaceTextByVariablesOfArray($context, $itemA);

        if(gettype($itemB) == 'string')
            $itemB = ChatFlowParser::replaceTextByVariables($context, $itemB);
        if(gettype($itemB) == 'array')
            $itemB = ChatFlowParser::replaceTextByVariablesOfArray($context, $itemB);
        
        $finalCondition = true;
        if($condition == 'equal' || $condition == '==') $finalCondition = $itemA == $itemB;
        if($condition == 'not equal' || $condition == '!=') $finalCondition = $itemA != $itemB;
        if($condition == 'is greater than' || $condition == '>') $finalCondition = $itemA > $itemB;
        if($condition == 'is greater or equal than' || $condition == '>=') $finalCondition = $itemA >= $itemB;
        if($condition == 'is less than' || $condition == '<') $finalCondition = $itemA < $itemB;
        if($condition == 'is less or equal than than' || $condition == '<=') $finalCondition = $itemA <= $itemB;

        if($condition == 'contains') {
            if(gettype($itemA) == 'string' && gettype($itemB) == 'string'){
                if(!$strict){
                    $itemA = mb_strtolower(ConversationFlow::remove_accents($itemA));
                    $itemB = mb_strtolower(ConversationFlow::remove_accents($itemB));
                }
                $finalCondition = str_contains($itemA, $itemB);
            }
            if(gettype($itemA) == 'array')
                $finalCondition = in_array($itemB, $itemA, $strict);
        }

        return $finalCondition;
    }

    public static function saveVariables($context, $variablesSection){
        foreach($variablesSection as $itemKey => $itemValue){
            ChatFlowParser::saveVariable($context, $itemKey, $itemValue);
        }
    }

    public static function replaceTextByVariablesOfArray($context, $array){
        $result = array();

        foreach($array as $itemKey => $itemValue){
            $newKey = $itemKey;
            $newValue = $itemValue;

            if(gettype($itemKey) == 'string')
                $newKey = ChatFlowParser::replaceTextByVariables($context, $itemKey);
            
            if(gettype($itemValue) == 'string')
            {
                $variableName = ChatFlowParser::getVariableNameFromText($itemValue);
                $variable = ChatFlowParser::getVariable($context, $variableName);

                if($variable == null || gettype($variable) == 'string') $newValue = ChatFlowParser::replaceTextByVariables($context, $itemValue);
                else $newValue = $variable;
            }
            else if(gettype($itemValue) == 'array')
            {
                $newValue = ChatFlowParser::replaceTextByVariablesOfArray($context, $itemValue);
            }

            $result[$newKey] = $newValue;
        }
        return $result;
    }

    public static function jsonToChatButton($context, $jsonObject, ?string $saveKey, $responsesList){
        if($jsonObject == null) return null;

        $nextResponse = fn() => ChatFlowParser::jsonObjectToResponse($context, $jsonObject->nextResponse, $responsesList);

        if(($nextResponse)() == null) return null;

        $buttonText = ChatFlowParser::replaceTextByVariables($context, $jsonObject->text);

        $saveKeyFunction = null;
        if($saveKey != null){
            $saveKeyFunction = function() use($context, $saveKey, $buttonText){
                $context->savedKeys[$saveKey] = $buttonText;
            };
            
        }

        $keyWordsToUse = array();
        if(isset($jsonObject->keywords)){
            foreach($jsonObject->keywords as $keyword){
                array_push($keyWordsToUse, ChatFlowParser::replaceTextByVariables($context, $keyword));
            }
        }

        $enabled = true;
        if(isset($jsonObject->enabled)){
            if(gettype($jsonObject->enabled) == 'boolean'){
                $enabled = $jsonObject->enabled;
            } else if(gettype($jsonObject->enabled) == 'object'){
                $enabledObject = $jsonObject->enabled;

                $condition = $enabledObject->condition;
                $itemA = $enabledObject->itemA;
                $itemB = $enabledObject->itemB;

                $strict = false;
                if(isset($jsonObject->strict)) $strict = $jsonObject->strict;

                $enabled = ChatFlowParser::checkConditions($context, $condition, $itemA, $itemB, $strict);
            }
        }

        $visible = true;
        if(isset($jsonObject->visible)){
            if(gettype($jsonObject->visible) == 'boolean'){
                $visible = $jsonObject->visible;
            } else if(gettype($jsonObject->visible) == 'object'){
                $visibleObject = $jsonObject->visible;

                $condition = $visibleObject->condition;
                $itemA = $visibleObject->itemA;
                $itemB = $visibleObject->itemB;

                $strict = false;
                if(isset($jsonObject->strict)) $strict = $jsonObject->strict;

                $visible = ChatFlowParser::checkConditions($context, $condition, $itemA, $itemB, $strict);
            }
        }

        return new ChatButton(
            $buttonText,
            $nextResponse,
            $keyWordsToUse,
            $saveKeyFunction,
            $visible,
            $enabled
        );
    }

    protected static function getVariableNameFromText(string $text){
        $text = trim($text);

        $result = preg_replace_callback('/\{[^}]*\}/', function($matches){
            $word = end($matches);
            $variableName = substr(substr($word, 1, strlen($word)), 0, strlen($word) - 2);
            return $variableName;
        }, $text);

        if(strlen($text) - 2 == strlen($result)) return $result;
        else return null;
    }

    protected static function replaceTextByVariables(BaseFlowConversation $context, string $text){
        return preg_replace_callback('/\{[^}]*\}/', function($matches) use ($context){
            $word = end($matches);
            $variableName = substr(substr($word, 1, strlen($word)), 0, strlen($word) - 2);
            $found = $context->savedKeys[$variableName] ?? '';

            if(gettype($found) == 'array') {
                $found = array_reduce($found, fn($prev, $item) => $prev.$item.', ', '');
                $found = substr($found, 0, strlen($found) - 2);
            }

            return $found;
        }, $text);
    }

    public static function getVariable($context, $variableName){
        if(!array_key_exists($variableName, $context->savedKeys)) return null;
        return $context->savedKeys[$variableName];
    }

    protected static function saveVariable($context, $variableName, $variableValue){
        $resultValue = $variableValue;
        if(gettype($variableValue) == 'object'){
            $variableValue = json_decode(json_encode($variableValue), true);
        }

        if(gettype($variableValue) == 'array'){
            $resultValue = ChatFlowParser::replaceTextByVariablesOfArray($context, $variableValue);
        } else if(gettype($variableValue) == 'string'){
            $resultValue = ChatFlowParser::replaceTextByVariables($context, $variableValue);
        }

        $context->savedKeys[$variableName] = $resultValue;
    }

}

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

    public static function get_root_from_json_to_chat_flow($context, string $jsonText) : ?BotResponse{
        $jsonObject = json_decode($jsonText);
        if(isset($jsonObject->responses->root))
            return ChatFlowParser::json_object_to_response($context, $jsonObject->responses->root, $jsonObject->responses);
        return null;
    }

    public static function json_to_chat_flow(
        BaseFlowConversation $context, 
        string $jsonText,
        ?BotResponse &$root = null
    ) : ?BotResponse {
        $jsonObject = json_decode($jsonText);

        // Load version
        if(isset($jsonObject->version) && gettype($jsonObject->version) == 'integer')
            $context->set_version($jsonObject->version);

        // Save variables
        ChatFlowParser::save_variables($context, $jsonObject->variables);

        // Get root
        if(isset($jsonObject->responses->root))
            $root = ChatFlowParser::json_object_to_response($context, $jsonObject->responses->root, $jsonObject->responses);

        // Start
        return ChatFlowParser::json_object_to_response($context, $jsonObject->responses->start, $jsonObject->responses);
    }

    public static function json_object_to_response(BaseFlowConversation $context, $jsonObject, $responsesList) {
        if($jsonObject == null) return null;
        
        if(gettype($jsonObject) == "string"){
            $arrayResponses = json_decode(json_encode($responsesList), true);

            if(!array_key_exists($jsonObject, $arrayResponses))
                return new BotReply(ChatFlowParser::replace_text_by_variables($context, $jsonObject));

            return ChatFlowParser::json_object_to_response($context, json_decode(json_encode($arrayResponses[$jsonObject])), $responsesList); 
        } else if(gettype($jsonObject) == "array"){
            $responseText = array();
            foreach($jsonObject as $itemText){
                array_push($responseText, ChatFlowParser::replace_text_by_variables($context, $itemText));
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
                $nextResponse = fn() => ChatFlowParser::json_object_to_response($context, $jsonObject->nextResponse, $responsesList);

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

            $context->get_conversation_flow()->update_contact(
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
                    array_push($responseText, ChatFlowParser::replace_text_by_variables($context, $itemText));
                }
                
            } else $responseText = ChatFlowParser::replace_text_by_variables($context, $jsonObject->text);
        }
        

        // Bot typing effect
        $botTypingSeconds = null;
        if(isset($jsonObject->botTypingSeconds)) $botTypingSeconds = $jsonObject->botTypingSeconds;

        // Additional parameters
        $additionalParameters = [];
        if(isset($jsonObject->additionalParameters)){
            if(gettype($jsonObject->additionalParameters) == 'string'){
                $additionalParameters = ChatFlowParser::get_variable($context, $jsonObject->additionalParameters);
            } else {
                $additionalParameters = json_decode(json_encode($jsonObject->additionalParameters), true);
                $additionalParameters = ChatFlowParser::replace_text_by_variables_of_array($context, $additionalParameters);
            }
        }

        // Auto root
        $autoRoot = false;
        if(isset($jsonObject->autoRoot)) $autoRoot = $jsonObject->autoRoot;

        // Attachment parameters
        $attachment = null;
        if(isset($jsonObject->attachment)){
            $attachmentObject = $jsonObject->attachment;
            $attachmentUrl = ChatFlowParser::replace_text_by_variables($context, $attachmentObject->url);
            
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
        if($type == EmptyResponse::get_parser_name()){
            return new EmptyResponse();
        }
        else if($type == BotReply::get_parser_name()){
            return new BotReply(
                $responseText,
                $nextResponse,
                $additionalParameters,
                $attachment,
                $saveLog,
                $botTypingSeconds
            );
        } else if($type == BotResponse::get_parser_name()){
            $buttons = null;
            if(isset($jsonObject->buttons)){
                $saveKey = isset($jsonObject->saveKey)? $jsonObject->saveKey : null;
                $buttons = array_map(fn($item) => ChatFlowParser::json_to_chat_button($context, $item, $saveKey, $responsesList), $jsonObject->buttons);
            }
            Storage::disk('public')->append('calling.txt', 'CALLED BOT RESPONSE');
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
        } else if($type == BotOpenQuestion::get_parser_name()){
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
                    $learningArray = ChatFlowParser::get_variable($context, $jsonObject->learningArray);
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
            $return = ChatFlowParser::process_functions($context, $jsonObject, $responsesList);
            if($return != null) return $return;
        }

        return new EmptyResponse();
    }

    public static function process_functions(BaseFlowConversation $context, $jsonObject, $responsesList){
        
        $functionName = $jsonObject->name;
        $functionName = strtolower($functionName);

        // Required map
        $map = null;
        if(isset($jsonObject->map)){
            $map = $jsonObject->map;
            $map = json_decode(json_encode($map), true);
            if(gettype($map) == 'string'){
                $map = ChatFlowParser::get_variable($context, ChatFlowParser::replace_text_by_variables($context, $map));
            }
            
        }
        // Required array
        $array = null;
        if(isset($jsonObject->array)){
            $array = $jsonObject->array;
            $array = json_decode(json_encode($array), true);
            if(gettype($array) == 'string'){
                $array = ChatFlowParser::get_variable($context, ChatFlowParser::replace_text_by_variables($context, $array));
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
            $find = ChatFlowParser::replace_text_by_variables($context, $jsonObject->find);

            // Save result
            if(gettype($map) == 'array') $saveResultVariable = array_key_exists($find, $map)? $map[$find] : null;
            
        } else if($functionName == 'where'){
            
            // Get key
            $key = ChatFlowParser::replace_text_by_variables($context, $jsonObject->key);

            // Get or condition
            $or = false;
            if(isset($jsonObject->or)) $or = $jsonObject->or;

            // Get expected value
            $value = json_decode(json_encode($jsonObject->value), true);
            if(gettype($value) == 'string') {
                $variableFromThis = ChatFlowParser::get_variable_name_from_text($jsonObject->value);
                if($variableFromThis == null) $value = ChatFlowParser::replace_text_by_variables($context, $jsonObject->value);
                else $value = ChatFlowParser::get_variable($context, $variableFromThis);
            }
            if(gettype($value) == 'array') $value = ChatFlowParser::replace_text_by_variables_of_array($context, $value);
            
            if(gettype($array) == 'array') {
                $saveResultVariable = array_filter($array, function($item, $itemKey) use($key, $value, $condition, $strict, $or) {
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
                }, ARRAY_FILTER_USE_BOTH);
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
                ChatFlowParser::save_variable($context, $eachVariable, null);
            }
        } else if($functionName == 'if'){

            $then = $jsonObject->then;
            $thenResponse = ChatFlowParser::json_object_to_response($context, $then, $responsesList);

            $else = $jsonObject->else;
            $elseResponse = ChatFlowParser::json_object_to_response($context, $else, $responsesList);
            
            if(isset($jsonObject->nextResponse) && $jsonObject->nextResponse != null)
            {
                if($thenResponse instanceof BotResponse)
                    $thenResponse->temporalRootResponse = fn() => ChatFlowParser::json_object_to_response(
                        $context, 
                        $jsonObject->nextResponse, 
                        $responsesList
                    );
                if($elseResponse instanceof BotResponse)
                    $elseResponse->temporalRootResponse = fn() => ChatFlowParser::json_object_to_response(
                        $context, 
                        $jsonObject->nextResponse, 
                        $responsesList
                    );
            }

            $result = ChatFlowParser::check_conditions(
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

            $preItem = ChatFlowParser::get_variable($context, 'item');
            foreach($arrayToUse as $eachItem){
                ChatFlowParser::save_variable($context, 'item', $eachItem);


            }
            ChatFlowParser::save_variable($context, 'item', $preItem);*/
        } else if($functionName == 'map'){
            $get = $jsonObject->get;

            $arrayToUse = $map ?? $array;
            $arrayToUse = json_decode(json_encode($arrayToUse), true);

            if($arrayToUse != null){
                $saveResultVariable = array_map(fn($item) => array_key_exists($get, $item)? $item[$get] : null, $arrayToUse);
                $saveResultVariable = array_filter($saveResultVariable);
            }
        }

        //Save result if needed
        if($saveResultVariable != null) ChatFlowParser::save_variable($context, $resultVariableName, $saveResultVariable);
        
        // Return next response
        if(isset($jsonObject->nextResponse) && $jsonObject->nextResponse != null)
            return ChatFlowParser::json_object_to_response($context, $jsonObject->nextResponse, $responsesList);
        
    }

    public static function check_conditions(BaseFlowConversation $context, $condition, $itemA, $itemB, $strict){

        if(gettype($itemA) == 'string')
            $itemA = ChatFlowParser::replace_text_by_variables($context, $itemA);
        if(gettype($itemA) == 'array')
            $itemA = ChatFlowParser::replace_text_by_variables_of_array($context, $itemA);

        if(gettype($itemB) == 'string')
            $itemB = ChatFlowParser::replace_text_by_variables($context, $itemB);
        if(gettype($itemB) == 'array')
            $itemB = ChatFlowParser::replace_text_by_variables_of_array($context, $itemB);
        
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

    public static function save_variables(BaseFlowConversation $context, $variablesSection){
        foreach($variablesSection as $itemKey => $itemValue){
            ChatFlowParser::save_variable($context, $itemKey, $itemValue);
        }
    }

    public static function replace_text_by_variables_of_array(BaseFlowConversation $context, $array){
        $result = array();

        foreach($array as $itemKey => $itemValue){
            $newKey = $itemKey;
            $newValue = $itemValue;

            if(gettype($itemKey) == 'string')
                $newKey = ChatFlowParser::replace_text_by_variables($context, $itemKey);
            
            if(gettype($itemValue) == 'string')
            {
                $variableName = ChatFlowParser::get_variable_name_from_text($itemValue);
                $variable = ChatFlowParser::get_variable($context, $variableName);

                if($variable == null || gettype($variable) == 'string') $newValue = ChatFlowParser::replace_text_by_variables($context, $itemValue);
                else $newValue = $variable;
            }
            else if(gettype($itemValue) == 'array')
            {
                $newValue = ChatFlowParser::replace_text_by_variables_of_array($context, $itemValue);
            }

            $result[$newKey] = $newValue;
        }
        return $result;
    }

    public static function json_to_chat_button(BaseFlowConversation $context, $jsonObject, ?string $saveKey, $responsesList){
        if($jsonObject == null) return null;

        $nextResponse = fn() => ChatFlowParser::json_object_to_response($context, $jsonObject->nextResponse, $responsesList);

        // IMPORTANT: Don't call nextResponse here. that CAN'T be null.
        // If is null, so there is runtime error, but can't be null
        // NextResponse can return empty response in worst cases

        $buttonText = ChatFlowParser::replace_text_by_variables($context, $jsonObject->text);

        $saveKeyFunction = null;
        if($saveKey != null){
            $saveKeyFunction = function() use($context, $saveKey, $buttonText){
                $context->savedKeys[$saveKey] = $buttonText;
            };
            
        }

        $keyWordsToUse = array();
        if(isset($jsonObject->keywords)){
            foreach($jsonObject->keywords as $keyword){
                array_push($keyWordsToUse, ChatFlowParser::replace_text_by_variables($context, $keyword));
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

                $enabled = ChatFlowParser::check_conditions($context, $condition, $itemA, $itemB, $strict);
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

                $visible = ChatFlowParser::check_conditions($context, $condition, $itemA, $itemB, $strict);
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

    protected static function get_variable_name_from_text(string $text){
        $text = trim($text);

        $result = preg_replace_callback('/\{[^}]*\}/', function($matches){
            $word = end($matches);
            $variableName = substr(substr($word, 1, strlen($word)), 0, strlen($word) - 2);
            return $variableName;
        }, $text);

        if(strlen($text) - 2 == strlen($result)) return $result;
        else return null;
    }

    protected static function replace_text_by_variables(BaseFlowConversation $context, string $text){
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

    public static function get_variable(BaseFlowConversation $context, $variableName){
        if(!array_key_exists($variableName, $context->savedKeys)) return null;
        return $context->savedKeys[$variableName];
    }

    protected static function save_variable(BaseFlowConversation $context, $variableName, $variableValue){
        $resultValue = $variableValue;
        if(gettype($variableValue) == 'object'){
            $variableValue = json_decode(json_encode($variableValue), true);
        }

        if(gettype($variableValue) == 'array'){
            $resultValue = ChatFlowParser::replace_text_by_variables_of_array($context, $variableValue);
        } else if(gettype($variableValue) == 'string'){
            $resultValue = ChatFlowParser::replace_text_by_variables($context, $variableValue);
        }

        $context->savedKeys[$variableName] = $resultValue;
    }

}

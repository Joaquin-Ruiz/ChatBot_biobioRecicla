<?php

namespace App\Classes;

use App\Contact;
use App\Classes\BotResponse;
use App\Classes\NlpProcessing\NlpScore;
use App\Classes\NlpProcessing\PairNlp;
use App\Classes\NlpProcessing\PairNlpOption;
use App\Conversations\BaseFlowConversation;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Outgoing\Question;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use DonatelloZa\RakePlus\RakePlus;

use App\Conversations\BotConversation;
use BotMan\BotMan\BotMan;
use Exception;
use Opis\Closure\SerializableClosure;

class ConversationFlow{

    /**
     * @var BaseFlowConversation
     */
    public BaseFlowConversation $rootContext;

    // Saved responses
    public $responses = array();

    /**
     * @var Contact
     */
    private $contact;

    /**
     * @var bool
     */
    private $logAnonymous = true;

    /**
     * User section define conversation flow for business purposes
     * @var int
     */
    private $userSection;

    public function __construct($rootContext, bool $initLogAnonymous = true)
    {
        $this->rootContext = $rootContext;
        if($initLogAnonymous) $this->set_log_anonymous(true);
    }

    // Setter for contact
    protected function set_contact(Contact $newContact){
        $this->contact = $newContact;
    }

    public function get_contact(){ return $this->contact; }

    public function update_contact(string $firstname, $phone, $email, $updateAnonymous = false){
        $this->contact->name = $firstname;
        $this->contact->phone = $phone;
        $this->contact->mail = $email;

        $this->contact->save();

        if($updateAnonymous) $this->set_log_anonymous(false);
    }

    // Setter for "logAnonymous"
    public function set_log_anonymous(bool $isAnonymous){
        $this->logAnonymous = $isAnonymous;

        if($isAnonymous && $this->contact == null){
            $this->set_contact(Contact::create([
                'name'=> 'Anonymous',
                'phone'=> '',
                'mail'=> ''
            ]));
        }
    }

    // Setter for "userSection"
    public function set_user_section(int $newUserSection){
        $this->userSection = $newUserSection;
    }

    // Process chatbot button options
    public function button_value_to_process($value){
        return str_replace(',', '', preg_replace('/\s+/', '', strtolower($value)));
    }

    public bool $flowFromJson = false;

    public function start_flow(BotResponse $botResponse, ?BotResponse $rootResponse = null){
        $this->create_question($this->rootContext, $botResponse, $rootResponse);
    }

    
    public static $lowProbability;
    public array $temporalRootResponses = [];
    public function create_question($context, BotResponse $botResponse, ?BotResponse $rootResponse = null, ?BotResponse $temporalRootResponse = null){           
        
        // Context is required
        if($context == null) return;
        if($context->getBot() == null) return;
        if($botResponse == null) return;

        if($botResponse instanceof EmptyResponse)
            return $this->create_question(
                $context, 
                $rootResponse,
                $rootResponse,
                $temporalRootResponse
            );

        // Get text to display
        $textToDisplay = gettype($botResponse->text) == 'array'? array_random($botResponse->text) : $botResponse->text;
        
        // Add question or response to responses
        array_push($this->responses, $botResponse->text);
        
        // If should save log, save conversation log
        if($botResponse->saveLog) $this->save_conversation_log();

        // Set root response to same bot response if auto root is true
        if($botResponse->autoRoot) $botResponse->rootResponse = clone $botResponse;
        // Set root response to bot response, ONLY if bot response hasn't root response
        else if($botResponse->rootResponse == null) $botResponse->rootResponse = $rootResponse;

        // Only declare root response to use (CAN BE TEMPORAL)
        $realRootResponse = $botResponse->rootResponse;
        $rootResponseToUse = $realRootResponse;

        if($botResponse->temporalRootResponse != null){
            $rootResponseToUse = ($botResponse->temporalRootResponse)();
            array_push($this->temporalRootResponses, $rootResponseToUse);
            $botResponse->temporalRootResponse = null;
        }
        else if($temporalRootResponse != null && $temporalRootResponse instanceof BotResponse){
            $rootResponseToUse = $temporalRootResponse;
        } else if($temporalRootResponse == null){
            array_pop($this->temporalRootResponses);
            $nextTemporalResponseToUse = end($this->temporalRootResponses);
            if($nextTemporalResponseToUse instanceof BotResponse) $rootResponseToUse = $nextTemporalResponseToUse;
        }

        Storage::disk('public')->append('rootTest.txt', ($temporalRootResponse != null)? 'NOT NULL' : 'NULL');

        // Add bot typing effect
        if($botResponse->botTypingSeconds != null) $context->getBot()->typesAndWaits($botResponse->botTypingSeconds);
        
        // Define root context to use, for functions that need "this" inside
        $rootContextToUse = $this->rootContext;

        // Call 'onExecute' function of bot responses
        if($botResponse->onExecute != null) $botResponse->onExecute->call($rootContextToUse, $rootContextToUse);        

        // Check if is open question
        if($botResponse instanceof BotOpenQuestion){
            $thisContext = $this;
            $question = Question::create($textToDisplay)
                ->fallback('Unable to ask question')
                ->callbackId('ask_'.count($this->responses));

            return $context->ask($question, function(Answer $answer) use ($thisContext, $botResponse, $rootResponseToUse, $realRootResponse, $rootContextToUse){
                if(!$this instanceof BotConversation) return;
                
                $processedAnswer = $botResponse->process_answer($answer->getText());

                // Run validation function of BotOpenQuestions
                if(gettype($processedAnswer) != 'boolean' && $botResponse->validationCallback->call($rootContextToUse, $processedAnswer, $rootContextToUse, $this)){
                    
                    // Answer is correct so continue or back to root response
                    // Add selected button to responses array
                    array_push($thisContext->responses, $processedAnswer);

                    // Call 'onValidatedAnswer' of BotOpenQuestions
                    if($botResponse->onValidatedAnswer != null) $botResponse->onValidatedAnswer->call($rootContextToUse, $processedAnswer, $rootContextToUse);

                    if($rootResponseToUse != null)
                        return $thisContext->create_question(
                            $this, 
                            ($botResponse->nextResponse) != null? 
                                $botResponse->nextResponse->call($rootContextToUse, $processedAnswer, $rootContextToUse) 
                                : $rootResponseToUse, 
                            $realRootResponse,
                            ($botResponse->nextResponse) != null? $rootResponseToUse : null
                        );
                    else if(($botResponse->nextResponse) != null)
                        return $thisContext->create_question(
                            $this, 
                            $botResponse->nextResponse->call($rootContextToUse, $processedAnswer, $rootContextToUse),
                            $realRootResponse,
                            $rootResponseToUse
                        );
                }
                
                // If has error message, say error message
                if($botResponse->errorMessage != null) {
                    $this->say($botResponse->errorMessage);
                    array_push($thisContext->responses, $botResponse->errorMessage);
                }
                // Display: Error custom response; repeat open question or back root response
                if($rootResponseToUse != null)
                    return $thisContext->create_question(
                        $this, 
                        $botResponse->errorResponse ?? $botResponse->onErrorBackToRoot? $rootResponseToUse : $botResponse, 
                        $realRootResponse,
                        ($botResponse->errorResponse != null)? $rootResponseToUse : ($botResponse->onErrorBackToRoot? null : $rootResponseToUse)
                    );
                else
                    return $thisContext->create_question(
                        $this, 
                        $botResponse->errorResponse ?? $botResponse, 
                        $realRootResponse,
                        $rootResponseToUse
                    );
                
            }, $botResponse->additionalParams);
        }

        // If buttons are null, so display bot response text and then display root response (it's like chatbot menu)
        if($botResponse->buttons == null){
            // Create outgoing message with possible attachment
            $outgoingMessage = OutgoingMessage::create($textToDisplay, $botResponse->attachment);
            $context->say($outgoingMessage, $botResponse->additionalParams);

            if($botResponse->nextResponse != null) return $this->create_question(
                $context, 
                $botResponse->nextResponse->call($this->rootContext, $rootContextToUse), 
                $realRootResponse,
                $rootResponseToUse);
            if($rootResponseToUse != null) return $this->create_question(
                $context, 
                $rootResponseToUse,
                $realRootResponse,
                null
            );
            return;
        }

        $buttonsToDisplay = array();
        $buttonsEnabled = array();
        foreach($botResponse->buttons as $buttonRef){
            if($buttonRef->enabled) {
                array_push($buttonsEnabled, $buttonRef);
                if($buttonRef->visible) array_push($buttonsToDisplay, $buttonRef);
            }
            else continue;
        }

        $botResponse->buttons = $buttonsEnabled;

        // If there are buttons, so create question
        $question = Question::create($textToDisplay)
            ->fallback('Unable to ask question')
            ->callbackId('ask_'.count($this->responses)); // Maybe this callback Id should be calculated according to $responses last id added
        if($botResponse->displayButtons)
            $question->addButtons(array_map( function($value){ return Button::create($value->text)->value($value->text);}, $buttonsToDisplay ));
        

        // Finally ask question and wait response
        $thisContext = $this;
        
        return $context->ask($question, function (Answer $answer) use ($thisContext, $context, $botResponse, $rootResponseToUse, $realRootResponse, $rootContextToUse){
            if(!$this instanceof BotConversation) return;
            $foundButtons = array();

            // TODO: use lowProbability with pairnlp
            ConversationFlow::$lowProbability = array();

            if ($answer->isInteractiveMessageReply()) {
                // Get selected pressed button
                $foundButtons = array_filter($botResponse->buttons, function($value, $key)  use($answer){
                    return $value->text == $answer->getValue();
                }, ARRAY_FILTER_USE_BOTH);

                $foundButtons = array_map(fn(ChatButton $item) => new PairNlp(
                        new NlpScore(1,1,1),
                        $item->text, 
                        $item->text, 
                        $item
                    ), 
                    $foundButtons
                );
            }
            else {
                $pairsValues = array_map(fn(ChatButton $item) => new PairNlpOption($item->text, $item->additionalKeywords, $item), $botResponse->buttons);
                $foundButtons = PairNlp::get_nlp_pairs(
                    $answer->getText(), 
                    $pairsValues
                );  
                PairNlp::sort($foundButtons);
            }

            // Just check if selected button is found
            if(count($foundButtons) <= 0){
                // If not found, display error message and repeat question
                if($botResponse->errorMessage != null) 
                    $this->say($botResponse->errorMessage, $botResponse->additionalParams);
                else {
                    if(count(ConversationFlow::$lowProbability) > 0){
                        ksort(ConversationFlow::$lowProbability);

                        //$firstElem = end(ConversationFlow::$lowProbability);
                        $indecisionResponse = $thisContext->get_indecision_response(
                            ConversationFlow::$lowProbability,
                            clone $botResponse,
                            $rootContextToUse,
                            $answer
                        );

                        return $thisContext->create_question(
                            $this, 
                            $indecisionResponse, 
                            $realRootResponse,
                            $rootResponseToUse
                        );
                    }

                    $this->say("'".$answer->getText()."' no lo entiendo. Intente nuevamente.", $botResponse->additionalParams);
                }

                return $thisContext->create_question(
                    $this, 
                    clone $botResponse, 
                    $realRootResponse,
                    $rootResponseToUse
                );
            }

            $foundButton = null;

            PairNlp::saveTest($foundButtons, 'beforeUnique');
            $foundButtons = PairNlp::nlp_unique($foundButtons);

            // DEBUG: 
            //$this->say("testFoundButtons: ".count($foundButtons), $botResponse->additionalParams);
            if(count($foundButtons) > 1){

                $finalButtons = PairNlp::filter_by_tolerance($foundButtons);

                if(count($finalButtons) > 1){
                    $indecisionResponse = $thisContext->get_indecision_response(
                        array_map(fn(PairNlp $item) => $item->value, $finalButtons),
                        clone $botResponse,
                        $rootContextToUse,
                        $answer
                    );

                    return $thisContext->create_question(
                        $this, 
                        $indecisionResponse, 
                        $realRootResponse, 
                        $rootResponseToUse
                    );
                }
                
                $foundButton = end($finalButtons);
            } else {
                // Get first found button 
                $foundButton = array_shift($foundButtons); 
            }

            if(!$foundButton instanceof PairNlp) throw new Exception('Found button is not Pair Nlp');

            // Add selected button to responses array
            array_push($thisContext->responses, $foundButton->value->text);

            // If response should be saved, so save conversation log
            if($botResponse->saveLog) $thisContext->save_conversation_log();

            // Execute custom on pressed from found button
            if($foundButton->value->onPressed != null) $foundButton->value->onPressed->call($rootContextToUse, $rootContextToUse);

            // Then go to bot response from found button
            return $thisContext->create_question(
                $this, 
                $foundButton->value->createBotResponse != null? $foundButton->value->createBotResponse->call($rootContextToUse, $rootContextToUse) : $foundButton->value->botResponse, 
                $realRootResponse,
                $rootResponseToUse
            );
            
        }, $botResponse->additionalParams);
    }

    public function get_indecision_response(array $buttons, BotResponse $botResponse, BaseFlowConversation $rootContextToUse, $answer){
        
        $negativeQuestion = new ChatButton('No, preguntar nuevamente', fn() => $botResponse, ['No']);
        array_push($buttons, $negativeQuestion);
        
        return new BotResponse(
            '??Quisiste decir alguna de estas opciones?',
            array_map(
                function(ChatButton $buttonValue) use ($rootContextToUse, $answer){
                    $onPressed = function() use($buttonValue, $rootContextToUse){
                        if($buttonValue->onPressed != null) $buttonValue->onPressed->call($rootContextToUse, $rootContextToUse);
                    };
                    return new ChatButton(
                        $buttonValue->text, 
                        $buttonValue->createBotResponse,
                        $buttonValue->additionalKeywords,
                        function()use($answer, $buttonValue, $onPressed,$rootContextToUse){
                            ($onPressed)();

                            // Add this option to knowledge base
                            if($buttonValue->text == 'No, preguntar nuevamente') return;

                            $diskName = 'chatknowledge';
                            $fileName = 'newknowledge.csv';

                            $existsKnowledgeContent = Storage::disk($diskName)->exists($fileName);
                            if(!$existsKnowledgeContent){
                                Storage::disk($diskName)->append($fileName, 'Person Answer,Expected,FlowName,Version');
                            }

                            $knowledgeContent = Storage::disk($diskName)->append(
                                $fileName, 
                                $answer->getText().','.$buttonValue->text.','.($rootContextToUse->get_flow_name()??'').','.($rootContextToUse->get_version() ?? '')
                            );                            
                        }
                    );
            }, 
            $buttons)
        );

        
    }

    public function save_conversation_log(){

        $contactWithResponses = null;
        if($this->contact != null){
            // Decode contact in json
            $contactInJson = json_decode($this->contact, true);
            // Add responses to array. So now we have an array with contact and responses
            $contactWithResponses = array_merge($this->responses, $contactInJson);
        } 
        
        // Encode array to json. This is data to finally save
        $dataToSaveJson = json_encode($contactWithResponses ?? $this->responses);

        // Get prefix. Change if is anonymous or not
        $prefixToSave = $this->logAnonymous? 'conversation_log_anonymous' : 'conversation_log';

        // Disk to use
        $diskToUse = $this->logAnonymous? 'chatlogs_anonymous' : 'chatlogs_contact';

        // Finally put file in storage
        Storage::disk($diskToUse)->put(
            $prefixToSave.'_'.($this->contact != null? $this->contact->id : str_replace(':', '_', now())).'.json',
            $dataToSaveJson
        );
    }

    public static function phone_regex(){
        return "/^[\+]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4,6}$/im";
    }
    public static function email_regex(){
        return "/^(([^<>()\[\]\.,;:\s@\???]+(\.[^<>()\[\]\.,;:\s@\???]+)*)|(\???.+\???))@(([^<>()[\]\.,;:\s@\???]+\.)+[^<>()[\]\.,;:\s@\???]{2,})$/";
    }

    public static function remove_accents($string) {
        $unwanted_array = array(    '??'=>'S', '??'=>'s', '??'=>'Z', '??'=>'z', '??'=>'A', '??'=>'A', '??'=>'A', '??'=>'A', '??'=>'A', '??'=>'A', '??'=>'A', '??'=>'C', '??'=>'E', '??'=>'E',
                            '??'=>'E', '??'=>'E', '??'=>'I', '??'=>'I', '??'=>'I', '??'=>'I', '??'=>'N', '??'=>'O', '??'=>'O', '??'=>'O', '??'=>'O', '??'=>'O', '??'=>'O', '??'=>'U',
                            '??'=>'U', '??'=>'U', '??'=>'U', '??'=>'Y', '??'=>'B', '??'=>'Ss', '??'=>'a', '??'=>'a', '??'=>'a', '??'=>'a', '??'=>'a', '??'=>'a', '??'=>'a', '??'=>'c',
                            '??'=>'e', '??'=>'e', '??'=>'e', '??'=>'e', '??'=>'i', '??'=>'i', '??'=>'i', '??'=>'i', '??'=>'o', '??'=>'n', '??'=>'o', '??'=>'o', '??'=>'o', '??'=>'o',
                            '??'=>'o', '??'=>'o', '??'=>'u', '??'=>'u', '??'=>'u', '??'=>'y', '??'=>'b', '??'=>'y' );
        $string = strtr( $string, $unwanted_array );
        return $string;
    }
}



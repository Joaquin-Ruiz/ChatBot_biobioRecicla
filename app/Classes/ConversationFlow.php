<?php

namespace App\Classes;

use App\Contact;
use App\Classes\BotResponse;
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

use App\Classes\NlpScore;
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
    public function create_question($context, BotResponse $botResponse, ?BotResponse $rootResponse = null){   
        // TODO: Detect multiple responses from Questions with process
        // Example: Hola, quiero de color rojo, también me gustaría verde y finalmente azul
        // Resultado: Ok, aqui tiene rojo, verde y azul
        
        
        // Context is required
        if($context == null) return;
        if($context->getBot() == null) return;
        if($botResponse == null) return;

        if($botResponse instanceof EmptyResponse)
            return $this->create_question(
                $context, 
                $rootResponse,
                $rootResponse
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

        // Only declare root response to use
        $rootResponseToUse = $botResponse->rootResponse;

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

            return $context->ask($question, function(Answer $answer) use ($thisContext, $botResponse, $rootResponseToUse, $rootContextToUse){
                $processedAnswer = $botResponse->processAnswer($answer->getText());

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
                            $rootResponseToUse
                        );
                    else if(($botResponse->nextResponse) != null)
                        return $thisContext->create_question(
                            $this, 
                            $botResponse->nextResponse->call($rootContextToUse, $processedAnswer, $rootContextToUse),
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
                        $rootResponseToUse
                    );
                else
                    return $thisContext->create_question(
                        $this, 
                        $botResponse->errorResponse ?? $botResponse, 
                        $rootResponseToUse
                    );
                
            }, $botResponse->additionalParams);
        }

        // If buttons are null, so display bot response text and then display root response (it's like chatbot menu)
        if($botResponse->buttons == null){
            // Create outgoing message with possible attachment
            $outgoingMessage = OutgoingMessage::create($textToDisplay, $botResponse->attachment);
            $context->say($outgoingMessage, $botResponse->additionalParams);

            if($botResponse->nextResponse != null) return $this->create_question($context, $botResponse->nextResponse->call($this->rootContext, $rootContextToUse), $rootResponseToUse);
            if($rootResponseToUse != null) return $this->create_question($context, $rootResponseToUse, $rootResponseToUse);
            return;
        }

        // If there are buttons, so create question
        $question = Question::create($textToDisplay)
            ->fallback('Unable to ask question')
            ->callbackId('ask_'.count($this->responses)); // Maybe this callback Id should be calculated according to $responses last id added
        if($botResponse->displayButtons)
            $question->addButtons(array_map( function($value){ return Button::create($value->text)->value($value->text);}, $botResponse->buttons ));

        // Finally ask question and wait response
        $thisContext = $this;
        
        return $context->ask($question, function (Answer $answer) use ($thisContext, $context, $botResponse, $rootResponseToUse, $rootContextToUse){
            $foundButtons = array();

            ConversationFlow::$lowProbability = array();

            if ($answer->isInteractiveMessageReply()) {
                // Get selected pressed button
                $foundButtons = array_filter($botResponse->buttons, function($value, $key)  use($answer){
                    return $value->text == $answer->getValue();
                }, ARRAY_FILTER_USE_BOTH);
            }
            else {
                // Get selected Typed button
                $wordsCount = count(explode(' ', $answer->getText()));

                $mainS1 = mb_strtolower(ConversationFlow::remove_accents($answer->getText()));  
                $mainS1 = preg_replace('/[^A-Za-z0-9 ]/', '', $mainS1);
                
                $phrase_scores = array();
                if($wordsCount > 1){
                    $rake = RakePlus::create($mainS1, 'es_AR');
                    $phrase_scores = $rake->get();
                    array_push($phrase_scores, $mainS1);
                } else array_push($phrase_scores, $answer->getText());    
                       
                foreach($botResponse->buttons as $value){                        
                    $s2 = mb_strtolower(ConversationFlow::remove_accents($value->text));
                    $s2 = preg_replace('/[^A-Za-z0-9 ]/', '', $s2);

                    if($mainS1 == $s2) {
                        $foundButtons[(string)1] = $value;
                        continue;
                    }

                    foreach($phrase_scores as $answerValue){  
                        $s1 = mb_strtolower(ConversationFlow::remove_accents($answerValue));
                        $s1 = preg_replace('/[^A-Za-z0-9 ]/', '', $s1);   
                        
                        Storage::disk('public')->put('testdebug.txt', $s1.' / '.$s2);

                        $mainNlpScore = NlpScore::getNlpScore($s1, $s2);

                        $requiredScore = new NlpScore(0.22, 0.4, 0.5);
                        $lowProbabilityScore = new NlpScore(0.12, 0.2, 0.3);

                        $botResponse->additionalParams[$value->text] = clone $mainNlpScore;

                        if(($s1 == $s2) || (
                            $mainNlpScore->valueA >= $requiredScore->valueA 
                            && $mainNlpScore->valueB >= $requiredScore->valueB
                            && $mainNlpScore->valueC >= $requiredScore->valueC
                        )){
                            $foundButtons[(string)$mainNlpScore->size()] = $value;
                        } else if(
                            $mainNlpScore->valueA >= $lowProbabilityScore->valueA
                            && $mainNlpScore->valueB >= $lowProbabilityScore->valueB
                            && $mainNlpScore->valueC >= $lowProbabilityScore->valueC
                        ){
                            $botResponse->additionalParams['lowProbability'] = true;
                            ConversationFlow::$lowProbability[$mainNlpScore->size()] = clone $value;
                        }

                        foreach($value->additionalKeywords as $keyword){
                            
                            $keywordNlpScore = NlpScore::getNlpScore($keyword, $answer->getText());
                            $botResponse->additionalParams[$keyword] = clone $keywordNlpScore;

                            if(($s1 == $s2) || (
                                $keywordNlpScore->valueA >= $requiredScore->valueA 
                                && $keywordNlpScore->valueB >= $requiredScore->valueB
                                && $keywordNlpScore->valueC >= $requiredScore->valueC
                            )){
                                $foundButtons[(string)$keywordNlpScore->size()] = $value;
                            } else if(
                                $keywordNlpScore->valueA >= $lowProbabilityScore->valueA
                                && $keywordNlpScore->valueB >= $lowProbabilityScore->valueB
                                && $keywordNlpScore->valueC >= $lowProbabilityScore->valueC
                            ){
                                $botResponse->additionalParams['lowProbability'] = true;
                                ConversationFlow::$lowProbability[$keywordNlpScore->size()] = clone $value;
                            }
                        }

                        $botResponse->additionalParams["keywords"] = $value->additionalKeywords;
                
                    }

                }         
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

                        return $thisContext->create_question($this, $indecisionResponse, $rootResponseToUse);
                    }

                    $this->say("'".$answer->getText()."' no lo entiendo. Intente nuevamente.", $botResponse->additionalParams);
                }

                return $thisContext->create_question(
                    $this, 
                    clone $botResponse, 
                    $rootResponseToUse
                );
            }

            $foundButton = null;
            ksort($foundButtons, SORT_ASC);

            $foundButtons = array_reduce(array_keys($foundButtons), function($prev, $keyItem) use($foundButtons) {
                $prePrev = array_filter(
                    $prev, 
                    fn($prevItem, $prevItemKey) => $prevItem->text != $foundButtons[$keyItem]->text,
                    ARRAY_FILTER_USE_BOTH
                );
                $prePrev[$keyItem] = $foundButtons[$keyItem];
                return $prePrev;    
            }, array());

            // DEBUG: 
            //$this->say("testFoundButtons: ".count($foundButtons), $botResponse->additionalParams);
            if(count($foundButtons) > 1){

                $maxKey = max(array_keys($foundButtons));
                $finalButtons = array();

                //array_push($finalButtons, $foundButtons[$maxKey]);
                $finalButtons[$maxKey] = $foundButtons[$maxKey];

                foreach($foundButtons as $eachKey => $eachButton){
                    if($eachKey == $maxKey) continue;

                    $diff = abs($eachKey - $maxKey);
                    $botResponse->additionalParams['diff'.$eachKey] = $diff;
                    if($diff <= 0.24) $finalButtons[$eachKey] = $eachButton; //array_push($finalButtons, $eachButton);
                }

                //$botResponse->additionalParams['FoundButtons'] = $foundButtons;

                //DEBUG: 
                //$this->say("multiple buttons: ".count($foundButtons), $botResponse->additionalParams);

                if(count($finalButtons) > 1){
                    $indecisionResponse = $thisContext->get_indecision_response(
                        $finalButtons,
                        clone $botResponse,
                        $rootContextToUse,
                        $answer
                    );

                    return $thisContext->create_question($this, $indecisionResponse, $rootResponseToUse);
                }
                
                $foundButton = end($finalButtons);

                //$foundButton = end($foundButtons);
            } else {
                // Get first found button 
                $foundButton = array_shift($foundButtons); 
            }

            // Add selected button to responses array
            array_push($thisContext->responses, $foundButton->text);

            // If response should be saved, so save conversation log
            if($botResponse->saveLog) $thisContext->save_conversation_log();

            // Execute custom on pressed from found button
            if($foundButton->onPressed != null) $foundButton->onPressed->call($rootContextToUse, $rootContextToUse);

            // Then go to bot response from found button
            return $thisContext->create_question(
                $this, 
                $foundButton->createBotResponse != null? $foundButton->createBotResponse->call($rootContextToUse, $rootContextToUse) : $foundButton->botResponse, 
                $rootResponseToUse
            );
            
        }, $botResponse->additionalParams);
    }

    public function get_indecision_response(array $buttons, BotResponse $botResponse, $rootContextToUse, $answer){
        
        $negativeQuestion = new ChatButton('No, preguntar nuevamente', fn() => $botResponse);
        array_push($buttons, $negativeQuestion);
        
        return new BotResponse(
            '¿Quisiste decir alguna de estas opciones?',
            array_map(
                function(ChatButton $buttonValue) use ($rootContextToUse, $answer){
                    $closure = fn() => $buttonValue->createBotResponse->call($rootContextToUse, $rootContextToUse) ?? $buttonValue->botResponse;
                    return new ChatButton(
                        $buttonValue->text, 
                        new SerializableClosure($closure),
                        [],
                        function()use($answer, $buttonValue){
                            // Add this option to knowledge base
                            if($buttonValue->text == 'No, preguntar nuevamente') return;

                            $diskName = 'chatknowledge';
                            $fileName = 'newknowledge.csv';

                            $existsKnowledgeContent = Storage::disk($diskName)->exists($fileName);
                            if(!$existsKnowledgeContent){
                                Storage::disk($diskName)->append($fileName, 'Person Answer,Expected');
                            }

                            $knowledgeContent = Storage::disk($diskName)->append(
                                $fileName, 
                                $answer->getText().','.$buttonValue->text
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
        return "/^(([^<>()\[\]\.,;:\s@\”]+(\.[^<>()\[\]\.,;:\s@\”]+)*)|(\”.+\”))@(([^<>()[\]\.,;:\s@\”]+\.)+[^<>()[\]\.,;:\s@\”]{2,})$/";
    }

    public static function remove_accents($string) {
        $unwanted_array = array(    'Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
                            'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U',
                            'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c',
                            'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
                            'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y' );
        $string = strtr( $string, $unwanted_array );
        return $string;
    }
}



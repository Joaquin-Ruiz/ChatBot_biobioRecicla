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

use \NlpTools\Tokenizers\WhitespaceTokenizer;
use \NlpTools\Similarity\JaccardIndex;
use \NlpTools\Similarity\CosineSimilarity;
use \NlpTools\Similarity\Simhash;

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

    public function start_flow(BotResponse $botResponse, ?BotResponse $rootResponse = null){
        $this->create_question($this->rootContext, $botResponse, $rootResponse);
    }

    

    
    public static $lowProbability;
    public function create_question($context, BotResponse $botResponse, ?BotResponse $rootResponse = null){        
        // Context is required
        if($context == null) return;
        if($context->getBot() == null) return;
        if($botResponse == null) return;

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
                // Run validation function of BotOpenQuestions
                if($botResponse->validationCallback->call($rootContextToUse, $answer, $rootContextToUse, $this)){
                    
                    // Answer is correct so continue or back to root response
                    // Add selected button to responses array
                    array_push($thisContext->responses, $answer->getText());

                    // Call 'onValidatedAnswer' of BotOpenQuestions
                    if($botResponse->onValidatedAnswer != null) $botResponse->onValidatedAnswer->call($rootContextToUse, $answer, $rootContextToUse);

                    if($rootResponseToUse != null)
                        return $thisContext->create_question(
                            $this, 
                            ($botResponse->nextResponse) != null? 
                                $botResponse->nextResponse->call($rootContextToUse, $answer, $rootContextToUse) 
                                : $rootResponseToUse, 
                            $rootResponseToUse
                        );
                    else if(($botResponse->nextResponse) != null)
                        return $thisContext->create_question(
                            $this, 
                            $botResponse->nextResponse->call($rootContextToUse, $answer, $rootContextToUse),
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
                foreach($botResponse->buttons as $value){
                    $s1 = mb_strtolower(ConversationFlow::remove_accents($answer->getText()));   
                    $s2 = mb_strtolower(ConversationFlow::remove_accents($value->text));

                    $s1 = preg_replace('/[^A-Za-z0-9 ]/', '', $s1);
                    $s2 = preg_replace('/[^A-Za-z0-9 ]/', '', $s2);

                    Storage::disk('public')->put('testdebug.txt', $s1.' / '.$s2);

                    $averageScore = NlpScore::Zero();

                    $mainNlpScore = NlpScore::getNlpScore($value->text, $answer->getText());
                    $keywordsCount = count($value->additionalKeywords);
                    $mainNlpScore->scale($keywordsCount > 0? 0.5 : 1);
                    $averageScore = $mainNlpScore;

                    foreach($value->additionalKeywords as $keyword){
                        
                        $keywordNlpScore = NlpScore::getNlpScore($keyword, $answer->getText());
                        $botResponse->additionalParams[$keyword] = clone $keywordNlpScore;

                        $weight = 0.5/$keywordsCount;

                        $idealScore = new NlpScore(0.3, 0.4, 0.6);
                        $perfectScore = new NlpScore(0.95, 0.95, 0.95);
                        if(
                            $keywordNlpScore->valueA >= $perfectScore->valueA
                            && $keywordNlpScore->valueB >= $perfectScore->valueB
                            && $keywordNlpScore->valueC >= $perfectScore->valueC
                        ) {  $averageScore = new NlpScore(1, 1, 1); }
                        else if(
                            $keywordNlpScore->valueA >= $idealScore->valueA
                            && $keywordNlpScore->valueB >= $idealScore->valueB
                            && $keywordNlpScore->valueC >= $idealScore->valueC
                        ) { $weight = .5; }
                        

                        $keywordNlpScore->scale($weight);
                        $averageScore->add($keywordNlpScore);
                        $averageScore->verifyClamp();
                    }

                    $botResponse->additionalParams["keywords"] = $value->additionalKeywords;
                    $botResponse->additionalParams[$value->text] = $averageScore;

                    $requiredScore = ($keywordsCount <= 0)? new NlpScore(0.22, 0.5, 0.5) : new NlpScore(0.2, 0.23, 0.3);

                    if(($s1 == $s2) || (
                        $averageScore->valueA >= $requiredScore->valueA 
                        && $averageScore->valueB >= $requiredScore->valueB
                        && $averageScore->valueC >= $requiredScore->valueC
                    )){
                        $foundButtons[$averageScore->valueA + $averageScore->valueB + $averageScore->valueC] = $value;
                    } else if($averageScore->valueA >= 0.12 && $averageScore->valueB >= 0.2 && $averageScore->valueC >= 0.3){
                        $botResponse->additionalParams['lowProbability'] = true;
                        ConversationFlow::$lowProbability[$averageScore->valueA + $averageScore->valueB + $averageScore->valueC] = clone $value;
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
                        array_push(ConversationFlow::$lowProbability,
                            new ChatButton('No, preguntar nuevamente', fn() => clone $botResponse)
                        );

                        $probabilityQuestion = new BotResponse(
                            '¿Quisiste decir alguna de estas opciones?',
                            array_map(
                                fn(ChatButton $buttonValue) => new ChatButton(
                                    $buttonValue->text, 
                                    fn() => $buttonValue->createBotResponse != null? $buttonValue->createBotResponse->call($rootContextToUse, $rootContextToUse) : $buttonValue->botResponse
                                ), 
                            ConversationFlow::$lowProbability)
                        );

                        return $thisContext->create_question($this, $probabilityQuestion, $rootResponseToUse);
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
            // DEBUG: $this->say("testFoundButtons: ".count($foundButtons), $botResponse->additionalParams);
            if(count($foundButtons) > 1){
                
                ksort($foundButtons);
                $foundButton = end($foundButtons);
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

class NlpScore{
    public float $valueA;
    public float $valueB;
    public float $valueC;

    public function __construct($valueA, $valueB, $valueC)
    {
        $this->valueA = $valueA;
        $this->valueB = $valueB;
        $this->valueC = $valueC;
    }

    public static function getNlpScore($text, $query) : NlpScore {
        $tok = new WhitespaceTokenizer();
        $J = new JaccardIndex();
        $cos = new CosineSimilarity();
        $simhash = new Simhash(16);

        $s1 = ConversationFlow::remove_accents(strtolower($query));   
        $s2 = ConversationFlow::remove_accents(strtolower($text));

        $s1 = preg_replace('/[^A-Za-z0-9 ]/', '', $s1);
        $s2 = preg_replace('/[^A-Za-z0-9 ]/', '', $s2);

        $setA = $tok->tokenize($s1);
        $setB = $tok->tokenize($s2);

        $valueA = $J->similarity(
            $setA,
            $setB
        );
        $valueB = $cos->similarity(
            $setA,
            $setB
        );
        $valueC = $simhash->similarity(
            $setA,
            $setB
        );

        return new NlpScore($valueA, $valueB, $valueC);
    }

    public function multiply(NlpScore $other){
        $this->valueA *= $other->valueA;
        $this->valueB *= $other->valueB;
        $this->valueC *= $other->valueC;
    }

    public function scale(float $scale){
        $this->valueA *= $scale;
        $this->valueB *= $scale;
        $this->valueC *= $scale;
    }

    public function add(NlpScore $other){
        $this->valueA += $other->valueA;
        $this->valueB += $other->valueB;
        $this->valueC += $other->valueC;
    }

    public function verifyClamp(){
        $this->valueA = NlpScore::clamp($this->valueA, 0, 1);
        $this->valueB = NlpScore::clamp($this->valueB, 0, 1);
        $this->valueC = NlpScore::clamp($this->valueC, 0, 1);
    }

    public static function clamp($current, $min, $max) {
        return max($min, min($max, $current));
    }

    public static function Zero() : NlpScore{
        return new NlpScore(0,0,0);
    }
}

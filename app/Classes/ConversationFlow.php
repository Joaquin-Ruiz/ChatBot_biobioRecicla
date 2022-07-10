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
            $question = Question::create($botResponse->text)
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
            $outgoingMessage = OutgoingMessage::create($botResponse->text, $botResponse->attachment);
            $context->say($outgoingMessage, $botResponse->additionalParams);

            if($botResponse->nextResponse != null) return $this->create_question($context, $botResponse->nextResponse->call($this->rootContext, $rootContextToUse), $rootResponseToUse);
            if($rootResponseToUse != null) return $this->create_question($context, $rootResponseToUse, $rootResponseToUse);
            return;
        }

        // If there are buttons, so create question
        $question = Question::create($botResponse->text)
            ->fallback('Unable to ask question')
            ->callbackId('ask_'.count($this->responses)) // Maybe this callback Id should be calculated according to $responses last id added
            ->addButtons(array_map( function($value){ return Button::create($value->text)->value($value->text);}, $botResponse->buttons ));

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
                $tok = new WhitespaceTokenizer();
                $J = new JaccardIndex();
                $cos = new CosineSimilarity();
                $simhash = new Simhash(16);
                

                // Get selected Typed button
                $foundButtons = array_filter($botResponse->buttons, function($value, $key)  
                use($thisContext, $answer, $tok, $J, $cos, $simhash, $botResponse){

                    $s1 = ConversationFlow::remove_accents(strtolower($answer->getText()));
                    $s2 = ConversationFlow::remove_accents(strtolower($value->text));

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

                    if(($s1 == $s2) || ($valueA >= 0.22 && $valueB >= 0.4 && $valueC >= 0.5)){

                        return true;
                    } else if($valueA >= 0.15 && $valueB >= 0.3 && $valueC >= 0.4){
                        $botResponse->additionalParams['lowProbability'] = true;
                        ConversationFlow::$lowProbability[$valueA + $valueB + $valueC] = clone $value;
                    }

                    $botResponse->additionalParams['resulterror'] = (string)$valueA.'/'.(string)$valueB.'/'.(string)$valueC;

                    return false;
                }, ARRAY_FILTER_USE_BOTH);
            }

            Storage::disk('public')->put('testdebug.txt', [
                count(ConversationFlow::$lowProbability)
            ]);

            // Just check if selected button is found
            if(count($foundButtons) <= 0 || count($foundButtons) > 1){
                // If not found, display error message and repeat question
                if($botResponse->errorMessage != null) 
                    $this->say($botResponse->errorMessage, $botResponse->additionalParams);
                else {
                    if(count(ConversationFlow::$lowProbability) > 0){
                        ksort(ConversationFlow::$lowProbability);

                        $firstElem = end(ConversationFlow::$lowProbability);

                        $probabilityQuestion = new BotResponse(
                            '¿Quisiste decir '.$firstElem->text.'?',
                            [
                                new ChatButton('Si', fn() => $firstElem->createBotResponse != null? $firstElem->createBotResponse->call($rootContextToUse, $rootContextToUse) : $firstElem->botResponse),
                                new ChatButton('No, preguntar nuevamente', fn() => clone $botResponse)
                            ]
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

            // Get first found button 
            $foundButton = array_shift($foundButtons); 

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
        if ( !preg_match('/[\x80-\xff]/', $string) )
        return $string;

        $chars = array(
        // Decompositions for Latin-1 Supplement
        chr(195).chr(128) => 'A', chr(195).chr(129) => 'A',
        chr(195).chr(130) => 'A', chr(195).chr(131) => 'A',
        chr(195).chr(132) => 'A', chr(195).chr(133) => 'A',
        chr(195).chr(135) => 'C', chr(195).chr(136) => 'E',
        chr(195).chr(137) => 'E', chr(195).chr(138) => 'E',
        chr(195).chr(139) => 'E', chr(195).chr(140) => 'I',
        chr(195).chr(141) => 'I', chr(195).chr(142) => 'I',
        chr(195).chr(143) => 'I', chr(195).chr(145) => 'N',
        chr(195).chr(146) => 'O', chr(195).chr(147) => 'O',
        chr(195).chr(148) => 'O', chr(195).chr(149) => 'O',
        chr(195).chr(150) => 'O', chr(195).chr(153) => 'U',
        chr(195).chr(154) => 'U', chr(195).chr(155) => 'U',
        chr(195).chr(156) => 'U', chr(195).chr(157) => 'Y',
        chr(195).chr(159) => 's', chr(195).chr(160) => 'a',
        chr(195).chr(161) => 'a', chr(195).chr(162) => 'a',
        chr(195).chr(163) => 'a', chr(195).chr(164) => 'a',
        chr(195).chr(165) => 'a', chr(195).chr(167) => 'c',
        chr(195).chr(168) => 'e', chr(195).chr(169) => 'e',
        chr(195).chr(170) => 'e', chr(195).chr(171) => 'e',
        chr(195).chr(172) => 'i', chr(195).chr(173) => 'i',
        chr(195).chr(174) => 'i', chr(195).chr(175) => 'i',
        chr(195).chr(177) => 'n', chr(195).chr(178) => 'o',
        chr(195).chr(179) => 'o', chr(195).chr(180) => 'o',
        chr(195).chr(181) => 'o', chr(195).chr(182) => 'o',
        chr(195).chr(182) => 'o', chr(195).chr(185) => 'u',
        chr(195).chr(186) => 'u', chr(195).chr(187) => 'u',
        chr(195).chr(188) => 'u', chr(195).chr(189) => 'y',
        chr(195).chr(191) => 'y',
        // Decompositions for Latin Extended-A
        chr(196).chr(128) => 'A', chr(196).chr(129) => 'a',
        chr(196).chr(130) => 'A', chr(196).chr(131) => 'a',
        chr(196).chr(132) => 'A', chr(196).chr(133) => 'a',
        chr(196).chr(134) => 'C', chr(196).chr(135) => 'c',
        chr(196).chr(136) => 'C', chr(196).chr(137) => 'c',
        chr(196).chr(138) => 'C', chr(196).chr(139) => 'c',
        chr(196).chr(140) => 'C', chr(196).chr(141) => 'c',
        chr(196).chr(142) => 'D', chr(196).chr(143) => 'd',
        chr(196).chr(144) => 'D', chr(196).chr(145) => 'd',
        chr(196).chr(146) => 'E', chr(196).chr(147) => 'e',
        chr(196).chr(148) => 'E', chr(196).chr(149) => 'e',
        chr(196).chr(150) => 'E', chr(196).chr(151) => 'e',
        chr(196).chr(152) => 'E', chr(196).chr(153) => 'e',
        chr(196).chr(154) => 'E', chr(196).chr(155) => 'e',
        chr(196).chr(156) => 'G', chr(196).chr(157) => 'g',
        chr(196).chr(158) => 'G', chr(196).chr(159) => 'g',
        chr(196).chr(160) => 'G', chr(196).chr(161) => 'g',
        chr(196).chr(162) => 'G', chr(196).chr(163) => 'g',
        chr(196).chr(164) => 'H', chr(196).chr(165) => 'h',
        chr(196).chr(166) => 'H', chr(196).chr(167) => 'h',
        chr(196).chr(168) => 'I', chr(196).chr(169) => 'i',
        chr(196).chr(170) => 'I', chr(196).chr(171) => 'i',
        chr(196).chr(172) => 'I', chr(196).chr(173) => 'i',
        chr(196).chr(174) => 'I', chr(196).chr(175) => 'i',
        chr(196).chr(176) => 'I', chr(196).chr(177) => 'i',
        chr(196).chr(178) => 'IJ',chr(196).chr(179) => 'ij',
        chr(196).chr(180) => 'J', chr(196).chr(181) => 'j',
        chr(196).chr(182) => 'K', chr(196).chr(183) => 'k',
        chr(196).chr(184) => 'k', chr(196).chr(185) => 'L',
        chr(196).chr(186) => 'l', chr(196).chr(187) => 'L',
        chr(196).chr(188) => 'l', chr(196).chr(189) => 'L',
        chr(196).chr(190) => 'l', chr(196).chr(191) => 'L',
        chr(197).chr(128) => 'l', chr(197).chr(129) => 'L',
        chr(197).chr(130) => 'l', chr(197).chr(131) => 'N',
        chr(197).chr(132) => 'n', chr(197).chr(133) => 'N',
        chr(197).chr(134) => 'n', chr(197).chr(135) => 'N',
        chr(197).chr(136) => 'n', chr(197).chr(137) => 'N',
        chr(197).chr(138) => 'n', chr(197).chr(139) => 'N',
        chr(197).chr(140) => 'O', chr(197).chr(141) => 'o',
        chr(197).chr(142) => 'O', chr(197).chr(143) => 'o',
        chr(197).chr(144) => 'O', chr(197).chr(145) => 'o',
        chr(197).chr(146) => 'OE',chr(197).chr(147) => 'oe',
        chr(197).chr(148) => 'R',chr(197).chr(149) => 'r',
        chr(197).chr(150) => 'R',chr(197).chr(151) => 'r',
        chr(197).chr(152) => 'R',chr(197).chr(153) => 'r',
        chr(197).chr(154) => 'S',chr(197).chr(155) => 's',
        chr(197).chr(156) => 'S',chr(197).chr(157) => 's',
        chr(197).chr(158) => 'S',chr(197).chr(159) => 's',
        chr(197).chr(160) => 'S', chr(197).chr(161) => 's',
        chr(197).chr(162) => 'T', chr(197).chr(163) => 't',
        chr(197).chr(164) => 'T', chr(197).chr(165) => 't',
        chr(197).chr(166) => 'T', chr(197).chr(167) => 't',
        chr(197).chr(168) => 'U', chr(197).chr(169) => 'u',
        chr(197).chr(170) => 'U', chr(197).chr(171) => 'u',
        chr(197).chr(172) => 'U', chr(197).chr(173) => 'u',
        chr(197).chr(174) => 'U', chr(197).chr(175) => 'u',
        chr(197).chr(176) => 'U', chr(197).chr(177) => 'u',
        chr(197).chr(178) => 'U', chr(197).chr(179) => 'u',
        chr(197).chr(180) => 'W', chr(197).chr(181) => 'w',
        chr(197).chr(182) => 'Y', chr(197).chr(183) => 'y',
        chr(197).chr(184) => 'Y', chr(197).chr(185) => 'Z',
        chr(197).chr(186) => 'z', chr(197).chr(187) => 'Z',
        chr(197).chr(188) => 'z', chr(197).chr(189) => 'Z',
        chr(197).chr(190) => 'z', chr(197).chr(191) => 's'
        );

        $string = strtr($string, $chars);

        return $string;
    }
}

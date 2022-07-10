<?php

namespace App\Classes;

use App\Classes\BotResponse;
use Closure;
use Opis\Closure\SerializableClosure;
use DonatelloZa\RakePlus\RakePlus;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\Inflector\Language;

class BotOpenQuestion extends BotResponse{
    /// SHOULD RETURN TRUE OR FALSE IF CAN CONTINUE
    public $validationCallback;

    public bool $processKeywordFromAnswer = false;
    public array $learningArrayToProcess = array();

    /**
     * @var BotResponse
     */
    public $errorResponse;

    /**
     * @var bool
     */
    public $onErrorBackToRoot = false;

    /**
     * @var ?Closure
     */
    public $onValidatedAnswer = null;

    public function processAnswer($answerText) {
        if(!$this->processKeywordFromAnswer) return $answerText;

        $inflector = InflectorFactory::createForLanguage(Language::SPANISH)->build();

        $rake = RakePlus::create($answerText, 'es_AR');

        $phrase_scores = $rake->keywords();
        $foundItems = array();

        //return join(';', $phrase_scores);
        foreach($phrase_scores as $itemValue){
            foreach($this->learningArrayToProcess as $learnItem){
                $s1 = ConversationFlow::remove_accents($itemValue);
                $s2 = ConversationFlow::remove_accents($learnItem);
                
                $s1 = mb_strtolower($s1);
                $s2 = mb_strtolower($s2);

                $s1 = $inflector->singularize($s1);
                $s2 = $inflector->singularize($s2);

                $nlpScore = NlpScore::getNlpScore($s1, $s2);
                $idealScore = new NlpScore(0.15, 0.2, 0.4);
                if(
                    $nlpScore->valueA >= $idealScore->valueA
                    && $nlpScore->valueB >= $idealScore->valueB
                    && $nlpScore->valueC >= $idealScore->valueC
                ) $foundItems[$nlpScore->valueA + $nlpScore->valueB + $nlpScore->valueC] = $learnItem;
            }
            
        }

        ksort($foundItems);
        return end($foundItems);

    }

    public function __construct(
        $text, 
        ?Closure $nextResponse = null, 
        ?Closure $validationCallback = null, 
        ?string $errorMessage = null, 
        ?Closure $onValidatedAnswer = null,
        ?Closure $onExecute = null,
        ?BotResponse $errorResponse = null, 
        bool $onErrorBackToRoot = false,
        bool $saveLog = false,
        ?float $botTypingSeconds = null,
        bool $processKeywordFromAnswer = false,
        array $learningArrayToProcess = array()
    )
    {
        parent::__construct(
            $text,
            null,
            $saveLog,
            $nextResponse,
            false,
            null,
            [],
            $errorMessage,
            null,
            $botTypingSeconds,
            false,
            $onExecute
        );
        $this->learningArrayToProcess = $learningArrayToProcess;
        $this->processKeywordFromAnswer = $processKeywordFromAnswer;
        $this->onValidatedAnswer = $onValidatedAnswer;
        $this->errorResponse = $errorResponse;
        $this->onErrorBackToRoot = $onErrorBackToRoot;
        $this->validationCallback = $validationCallback ?? fn() => true;
    }
}

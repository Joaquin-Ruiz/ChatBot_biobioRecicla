<?php

namespace App\Classes;

use App\Classes\BotResponse;
use Closure;
use Opis\Closure\SerializableClosure;
use DonatelloZa\RakePlus\RakePlus;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\Inflector\Language;
use Illuminate\Support\Facades\Storage;

class BotOpenQuestion extends BotResponse{
    /// SHOULD RETURN TRUE OR FALSE IF CAN CONTINUE
    public $validationCallback;

    public bool $processKeywordFromAnswer = false;
    public array $learningArrayToProcess = array();
    public bool $isMultiple = false;

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

        $phrase_scores = $rake->get();
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

                //if($s1 == $s1) return $learnItem;

                $nlpScore = NlpScore::getNlpScore($s1, $s2);
                $idealScore = new NlpScore(0.15, 0.2, 0.4);
                if(
                    $nlpScore->valueA >= $idealScore->valueA
                    && $nlpScore->valueB >= $idealScore->valueB
                    && $nlpScore->valueC >= $idealScore->valueC
                ) {
                    array_push($foundItems, new PairNlp($nlpScore, $s1, $learnItem));
                } 
            } 

        }

        PairNlp::sort($foundItems);
        //PairNlp::saveTest($foundItems);

        if($this->isMultiple) return PairNlp::get_values($foundItems);
        
        $lastItem = end($foundItems);
        if($lastItem != null) return $lastItem->final_value();
        return false;

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
        array $learningArrayToProcess = array(),
        bool $isMultiple = false
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
        $this->isMultiple = $isMultiple;
    }
}

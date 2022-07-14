<?php

namespace App\Classes;

use App\Classes\BotResponse;
use App\Classes\NlpProcessing\NlpScore;
use App\Classes\NlpProcessing\PairNlp;
use App\Classes\NlpProcessing\PairNlpOption;
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

    public function process_answer($answerText) {
        if(!$this->processKeywordFromAnswer) return $answerText; 
        
        $finalKeywordsToUse = array();
        foreach($this->learningArrayToProcess as $eachKey => $eachItem){
            array_push(
                $finalKeywordsToUse, 
                (gettype($eachKey) != 'integer'?  new PairNlpOption($eachKey, gettype($eachItem) == 'string'? explode(',', $eachItem) : $eachItem) 
                : new PairNlpOption($eachItem, []))
            );
        }

        Storage::disk('public')->put('testProcess.txt', array_reduce($finalKeywordsToUse, fn($prev, $item) => $prev.$item.'|', ''));
        $foundItems = PairNlp::get_nlp_pairs($answerText, $finalKeywordsToUse, new NlpScore(0.15, 0.2, 0.4));
        
        PairNlp::sort($foundItems);

        if($this->isMultiple) return count($foundItems) > 0? PairNlp::get_values($foundItems) : false;
        
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

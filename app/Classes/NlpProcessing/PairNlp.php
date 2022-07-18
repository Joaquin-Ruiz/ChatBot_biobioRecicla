<?php

namespace App\Classes\NlpProcessing;

use App\Classes\ConversationFlow;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\Inflector\Language;
use DonatelloZa\RakePlus\RakePlus;
use Illuminate\Support\Facades\Storage;

class PairNlp{
    public const DEBUG = true;

    public NlpScore $nlpScore;
    public string $typed;
    public string $comparingReference;

    public ?string $evaluated;
    public ?string $compared;
    public $value;

    public function weight() {return $this->nlpScore->size(); }
    public function sum() { return $this->nlpScore->sum(); }
    public function final_value(){ return $this->comparingReference; }

    public function __construct(NlpScore $nlpScore, string $typed, string $comparingReference, $value = null, $evaluated = null, $compared = null)
    {
        $this->nlpScore = $nlpScore;
        $this->typed = $typed;
        $this->comparingReference = $comparingReference;
        $this->value = $value;
        $this->evaluated = $evaluated;
        $this->compared = $compared;
    }
    
    public static function sort(array &$arrayWithPairs){
        return usort($arrayWithPairs, function(PairNlp $item, PairNlp $other) {
           if($item->weight() == $other->weight()) return 0;
           return ($item->weight() < $other->weight())? -1 : 1;
        });
    }

    public static function get_values(array &$arrayWithPairs){
        if(count($arrayWithPairs) <= 0) return [];
        return array_unique(array_map(fn($item) => $item->final_value(), $arrayWithPairs));
    }

    public static function nlp_unique(array $arrayWithPairs){
        if(count($arrayWithPairs) <= 0) return [];

        $foundItems = array();

        foreach($arrayWithPairs as $item){
            if(!$item instanceof PairNlp) continue;

            $foundLocalKey = array_filter($foundItems, fn(PairNlp $localItem) => $localItem->final_value() == $item->final_value());
            $foundItem = end($foundLocalKey);

            if($foundItem == null) array_push($foundItems, $item);
            else{
                // Check if is major nlp score or not this item
                if($item->weight() > $foundItem->weight()){
                    $foundItems = array_filter($foundItems, fn(PairNlp $filterItem) => !PairNlp::is_equal($filterItem, $foundItem));
                    array_push($foundItems, $item);
                }
            }
        }
        
        return $foundItems;
    }

    public static function clamp($current, $min, $max) {
        return max($min, min($max, $current));
    }

    public static function saveTest(array $arrayWithPairs, string $testName = 'nlptest'){
        Storage::disk('chatknowledge')->put(
            $testName.'.csv', 
            'typed,finalValue,evaluated,compared,weight,sum'
        );

        foreach($arrayWithPairs as $eachItem){
            Storage::disk('chatknowledge')->append(
                $testName.'.csv', 
                $eachItem->typed.','.$eachItem->final_value().','.($eachItem->compared ?? '').','.($eachItem->evaluated ?? '').','.$eachItem->weight().','.$eachItem->sum()
            );
        }
        
    }

    public static function is_equal(PairNlp $itemA, PairNlp $itemB){
        return $itemA->final_value() == $itemB->final_value();
    }

    public static function filter_by_tolerance($arrayWithPairs, float $tolerance = 0.24){
        PairNlp::sort($arrayWithPairs);
        $maxPair = end($arrayWithPairs);

        $finalButtons = array();
        array_push($finalButtons, $maxPair);

        PairNlp::saveTest($arrayWithPairs, 'filtering');

        foreach($arrayWithPairs as $eachItem){
            if(!$eachItem instanceof PairNlp) continue;
            if(PairNlp::is_equal($maxPair, $eachItem)) continue;

            $diff = abs($maxPair->sum() - $eachItem->sum());
            if($diff <= $tolerance) array_push($finalButtons, $eachItem);
        }

        PairNlp::saveTest($finalButtons, 'finalButtons');
        return $finalButtons;
    }

    public static function get_nlp_pairs(
        string $typedAnswer, 
        array $expectedValues,
        ?NlpScore $requiredScore = null,
        ?NlpScore $lowProbabilityScore = null
    ){
        $foundValues = array();

        $wordsCount = count(explode(' ', $typedAnswer));
        $inflector = InflectorFactory::createForLanguage(Language::SPANISH)->build();

        $mainS1 = mb_strtolower(ConversationFlow::remove_accents($typedAnswer));  
        $mainS1 = preg_replace('/[^A-Za-z0-9 ]/', '', $mainS1);
        $mainS1 = $inflector->singularize($mainS1);
        
        $phrase_scores = array();
        if($wordsCount > 1){
            $rake = RakePlus::create($mainS1, 'es_AR');
            $phrase_scores = $rake->get();
            array_push($phrase_scores, $mainS1);
        } else array_push($phrase_scores, $typedAnswer);    

        if($requiredScore == null) $requiredScore = new NlpScore(0.22, 0.4, 0.5);
        if($lowProbabilityScore == null) $lowProbabilityScore = new NlpScore(0.12, 0.2, 0.3);
                
        foreach($expectedValues as $value){    
            if(!$value instanceof PairNlpOption) continue;              
            $s2 = mb_strtolower(ConversationFlow::remove_accents($value->main));
            $s2 = preg_replace('/[^A-Za-z0-9 ]/', '', $s2);
            $s2 = $inflector->singularize($s2);

            if($mainS1 == $s2) {
                array_push($foundValues, new PairNlp(new NlpScore(1, 1, 1), $typedAnswer, $value->main, $value->value, $mainS1,$s2));
                continue;
            }

            foreach($phrase_scores as $answerValue){  
                $s1 = mb_strtolower(ConversationFlow::remove_accents($answerValue));
                $s1 = preg_replace('/[^A-Za-z0-9 ]/', '', $s1);   
                $s1 = $inflector->singularize($s1);

                $mainNlpScore = NlpScore::getNlpScore($s1, $s2);

                if(($s1 == $s2) || (
                    $mainNlpScore->valueA >= $requiredScore->valueA 
                    && $mainNlpScore->valueB >= $requiredScore->valueB
                    && $mainNlpScore->valueC >= $requiredScore->valueC
                )){
                    array_push($foundValues, new PairNlp($mainNlpScore, $typedAnswer, $value->main, $value->value, $s1, $s2));
                } else if(
                    $mainNlpScore->valueA >= $lowProbabilityScore->valueA
                    && $mainNlpScore->valueB >= $lowProbabilityScore->valueB
                    && $mainNlpScore->valueC >= $lowProbabilityScore->valueC
                ){
                    //array_push(ConversationFlow::$lowProbability, clone $value); 
                    ConversationFlow::$lowProbability[$mainNlpScore->sum()] = clone $value->value;
                }

                foreach($value->keywords as $keyword){
                    $keyword = mb_strtolower(ConversationFlow::remove_accents($keyword));
                    $keyword = preg_replace('/[^A-Za-z0-9 ]/', '', $keyword); 
                    $keyword = $inflector->singularize($keyword);
                    if(strlen($keyword) <= 0) continue;

                    Storage::disk('public')->put('test.txt', $keyword.'/'.$s1);
                    
                    $keywordNlpScore = NlpScore::getNlpScore($s1, $keyword);

                    if(($keyword == $s1) || (
                        $keywordNlpScore->valueA >= $requiredScore->valueA 
                        && $keywordNlpScore->valueB >= $requiredScore->valueB
                        && $keywordNlpScore->valueC >= $requiredScore->valueC
                    )){
                        array_push($foundValues, new PairNlp($keywordNlpScore, $typedAnswer, $value->main, $value->value, $s1, $keyword));
                    } else if(
                        $keywordNlpScore->valueA >= $lowProbabilityScore->valueA
                        && $keywordNlpScore->valueB >= $lowProbabilityScore->valueB
                        && $keywordNlpScore->valueC >= $lowProbabilityScore->valueC
                    ){
                        //array_push(ConversationFlow::$lowProbability, clone $value); 
                        ConversationFlow::$lowProbability[$keywordNlpScore->sum()] = clone $value->value;
                    }
                }
        
            }

        }  

        if(PairNlp::DEBUG) PairNlp::saveTest($foundValues, '//debug//getNlpValues'.gmdate("Y-m-d\TH-i-s\Z", time()));
        return $foundValues;
    }
}
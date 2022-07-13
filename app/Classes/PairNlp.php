<?php

namespace App\Classes;

use Illuminate\Support\Facades\Storage;

class PairNlp{
    public NlpScore $nlpScore;
    public string $typed;
    public string $comparingReference;

    public function weight() {return $this->nlpScore->size(); }
    public function final_value(){ return $this->comparingReference; }

    public function __construct(NlpScore $nlpScore, string $typed, string $comparingReference)
    {
        $this->nlpScore = $nlpScore;
        $this->typed = $typed;
        $this->comparingReference = $comparingReference;
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

    public static function clamp($current, $min, $max) {
        return max($min, min($max, $current));
    }

    public static function saveTest(array $arrayWithPairs){
        Storage::disk('chatknowledge')->put(
            'nlptest.csv', 
            'typed,finalValue,weight'
        );

        foreach($arrayWithPairs as $eachItem){
            Storage::disk('chatknowledge')->append(
                'nlptest.csv', 
                $eachItem->typed.','.$eachItem->final_value().','.$eachItem->weight()
            );
        }
        
    }
}
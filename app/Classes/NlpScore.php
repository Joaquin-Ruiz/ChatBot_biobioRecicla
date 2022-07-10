<?php

namespace App\Classes;

use \NlpTools\Tokenizers\WhitespaceTokenizer;
use \NlpTools\Similarity\JaccardIndex;
use \NlpTools\Similarity\CosineSimilarity;
use \NlpTools\Similarity\Simhash;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\Inflector\Language;

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

        $inflector = InflectorFactory::createForLanguage(Language::SPANISH)->build();
        $tok = new WhitespaceTokenizer();
        $J = new JaccardIndex();
        $cos = new CosineSimilarity();
        $simhash = new Simhash(16);

        $s1 = ConversationFlow::remove_accents(strtolower($query));   
        $s2 = ConversationFlow::remove_accents(strtolower($text));

        $s1 = preg_replace('/[^A-Za-z0-9 ]/', '', $s1);
        $s2 = preg_replace('/[^A-Za-z0-9 ]/', '', $s2);

        $s1 = $inflector->singularize($s1);
        $s2 = $inflector->singularize($s2);

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
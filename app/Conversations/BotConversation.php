<?php

namespace App\Conversations;

use App\Classes\BioioRecicla\PuntoLimpio;
use BotMan\BotMan\Messages\Incoming\Answer;

use App\Classes\BotResponse;
use App\Classes\BotOpenQuestion;
use App\Classes\BotReply;
use App\Classes\ChatButton;
use App\Classes\ChatFlowParser;
use App\Classes\ConversationFlow;
use Illuminate\Support\Facades\Storage;

use \NlpTools\Tokenizers\WhitespaceTokenizer;
use \NlpTools\Similarity\JaccardIndex;
use \NlpTools\Similarity\CosineSimilarity;
use \NlpTools\Similarity\Simhash;

define('HUMAN', 1);
define('BUSINESS', 0);

class BotConversation extends BaseFlowConversation
{    
    /**
     * Start the conversation
     */
    public function init()
    {      
        $this->start_flow_from_json('officialchatflow');
    }
}


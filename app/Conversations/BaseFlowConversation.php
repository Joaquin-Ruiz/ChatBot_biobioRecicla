<?php

namespace App\Conversations;

use App\Contact;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Conversations\Conversation;

use App\Classes\ConversationFlow;
use App\Classes\BotResponse;
use App\Classes\BotOpenQuestion;
use App\Classes\ChatButton;
use App\Classes\ChatFlowParser;
use Illuminate\Support\Facades\Storage;

abstract class BaseFlowConversation extends Conversation
{
    /**
     * @var ConversationFlow
     */
    protected ConversationFlow $conversationFlow;

    /**
     * Used for variables in json chat flow
     */
    public array $savedKeys = array();

    protected function start_flow(BotResponse $firstResponse, ?BotResponse $rootResponse = null){
        $this->conversationFlow->start_flow($firstResponse, $rootResponse);
    }

    protected function start_flow_from_json($jsonName){
        $contents = Storage::disk('jsonchatflows')->get($jsonName.'.json');
        $flow = ChatFlowParser::jsonToChatFlow($this, $contents);
        $root = ChatFlowParser::getRootFromJsonToChatFlow($this, $contents);
        if($flow == null) return;
        $this->conversationFlow->flowFromJson = true;
        $this->start_flow($flow, $root);
    }

    public function getConversationFlow() { return $this->conversationFlow; }

    abstract protected function init();
    
    /**
     * Start the conversation
     */
    public function run()
    {
        $this->conversationFlow = new ConversationFlow($this);
        $this->init();
    }
}


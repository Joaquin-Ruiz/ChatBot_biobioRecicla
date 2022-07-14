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

    protected ?int $version = null;
    protected ?string $flowName = null;

    /**
     * Used for variables in json chat flow
     */
    public array $savedKeys = array();
    
    public function get_version() { return $this->version; }
    public function set_version(int $newVersion) {$this->version = $newVersion;}
    public function get_flow_name() { return $this->flowName; }

    protected function start_flow(
        BotResponse $firstResponse, 
        ?BotResponse $rootResponse = null,
        ?int $version = null,
        ?string $flowName = null
    ){
        $this->conversationFlow->start_flow($firstResponse, $rootResponse);
        $this->version = $version;
        $this->flowName = $flowName;
    }

    protected function start_flow_from_json($jsonName){
        $contents = Storage::disk('jsonchatflows')->get($jsonName.'.json');
        $root = null;
        $flow = ChatFlowParser::json_to_chat_flow($this, $contents, $root);
        if($flow == null) return;
        $this->flowName = $jsonName;
        $this->conversationFlow->flowFromJson = true;
        $this->start_flow($flow, $root);
    }

    public function get_conversation_flow() { return $this->conversationFlow; }

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


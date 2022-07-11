<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Conversations\DemoConversations;
use BotMan\BotMan\BotMan;

class ChatController extends Controller
{
    function index(BotMan $bot){
        $bot->startConversation(new DemoConversations);
    }

}
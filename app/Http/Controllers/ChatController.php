<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Conversations\BotConversation;
use App\Conversations\DemoConversations;

class ChatController extends Controller
{
    function index($bot){
        $bot->startConversation(new DemoConversations); // Use "DemoConversations" for demo
    }

}
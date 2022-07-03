<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Conversations\BotConversation;
use App\Conversations\DemoConversations;

class ChatController extends Controller
{
    function index($bot){
        $bot->startConversation(new BotConversation); // Use "DemoConversations" for demo
    }

}
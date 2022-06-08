<?php

namespace App\Conversations;

use App\Contact;
use Illuminate\Foundation\Inspiring;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Outgoing\Question;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Conversations\Conversation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BotResponse{ // Can be a question
    public $text;
    public $buttons;    // nullable
    public $save;       // true - false / Save history

    function __construct(string $text, ?array $buttons = null, bool $save = false)
    {
        $this->text = $text;
        $this->save = $save;
        $this->buttons = $buttons;
    }
}

class HumanResponse{
    public $text;
    public $usedButton; // nullable
}

class ChatButton{
    public $text;
    public $botResponse;

    function __construct(string $text, BotResponse $botResponse)
    {
        $this->text = $text;
        $this->botResponse = $botResponse;
    }
}

class ChatReady{
    public $ready = true;
}

class BotConversation extends Conversation
{
    protected $firstname;

    protected $phone;

    protected $email;

    protected $contacto;

    protected $click1;

    protected $Responses = array();

    protected $Preguntas;

    protected $Bot_responses;
    public function askName()
    {
        $this->ask('Hola! Cual es su nombre? Para dirigirme a usted.', function(Answer $answer) {
            // Guardar resultado
            $this->firstname = $answer->getText();

            $this->say('Un placer conocerle '.$this->firstname);
            
            $this->askWhat();
        });
    }

    public function askWhat(){
        
        
        $question = Question::create($this->firstname.', es usted una persona individual o representa a una empresa? Debe hacer click en los siguentes botones para responder:')
        ->fallback('Incapaz de hacer la pregunta')
        ->callbackId('create_database')
        ->addButtons([
            Button::create('Soy una persona individual')->value('individual'),
            Button::create('Represento a una empresa')->value('empresa'),
        ]);

        $this->ask($question, function (Answer $answer) {
        // Detect if button was clicked:
        if ($answer->isInteractiveMessageReply()) {
            if ($answer->getValue() == 'individual'){
                $this->phone = "No es una empresa";
                $this->email = "No es una empresa";
                $this->contacto = Contact::create([
                    'name'=> $this->firstname,
                    'phone'=> $this->phone,
                    'mail'=> $this->email
                ]);
                $bool = 1;
                $this->test($bool);
            }else if($answer->getValue() == 'empresa'){
                $bool = 0;
                $this->askCellphone($bool);
            } // will be either 'yes' or 'no'
        }
        });
    }
    
    public function askCellphone($bool)
    {
        if($bool == 1){
            $this->ask('Perdon, el número debe estar en el formato de "+56912345678", intente de nuevo por favor', function(Answer $answer) {
                $answer->getText(); // Guardar resultado
                if(\preg_match("/^[\+]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4,6}$/im", $answer)){
                    $this->phone = $answer->getText();
                    $this->say('Gracias '.$this->firstname);
                    $bool = 0;
                    $this->askEmail($bool);
                } else{
                    $bool = 1;
                    $this->askCellphone($bool);
                }
                
            });
        }
        $question = Question::create($this->firstname.', esta usted de acuerdo con que nos proporcione su número de teléfono y email para que podamos contactarlo para una atención mas personalizada?')
        ->fallback('Incapaz de hacer la pregunta')
        ->callbackId('create_database')
        ->addButtons([
            Button::create('Si, me parece bien')->value('si'),
            Button::create('No estoy de acuerdo')->value('no'),
        ]);

        $this->ask($question, function (Answer $answer) {
        // Detect if button was clicked:
        if ($answer->isInteractiveMessageReply()) {
            if ($answer->getValue() == 'si'){
                $this->ask('Bien! Cúal es su número de teléfono?', function(Answer $answer) {
                    // /(\+56|0056|56)?[ -]*(9)[ -]*([0-9][ -]*){8}/
                    $answer->getText(); // Guardar resultado
                    if(\preg_match("/^[\+]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4,6}$/im", $answer)){
                        $this->phone = $answer->getText();
                        $this->say('Gracias '.$this->firstname);
                        $bool = 0;
                        $this->askEmail($bool);
                    } else{
                        $bool = 1;
                        $this->askCellphone($bool);
                    }
                });
            }else if($answer->getValue() == 'no'){
                $this->say('OK! No hay problema!');
                $this->phone = "No dió número";
                $this->email = "No dió email";
                $this->contacto = Contact::create([
                    'name'=> $this->firstname,
                    'phone'=> $this->phone,
                    'mail'=> $this->email
                ]);
                $bool = 0;
                $this->test($bool);
            } // will be either 'yes' or 'no'
        }
        });
    }
    public function askEmail($bool)
    {
        if($bool == 1){
            $this->ask('Perdon, el email debe estar en el formato de "tumail@dominio.com", intente de nuevo por favor', function(Answer $answer) {
                $answer->getText(); // Guardar resultado
                if(\preg_match("/^(([^<>()\[\]\.,;:\s@\”]+(\.[^<>()\[\]\.,;:\s@\”]+)*)|(\”.+\”))@(([^<>()[\]\.,;:\s@\”]+\.)+[^<>()[\]\.,;:\s@\”]{2,})$/", $answer)){
                    $this->email = $answer->getText();
                    $this->contacto = Contact::create([
                        'name'=> $this->firstname,
                        'phone'=> $this->phone,
                        'mail'=> $this->email
                    ]);
                    $this->say('Gracias '.$this->firstname);
                    $bool = 0;
                    $this->test($bool);
                } else{
                    $bool = 1;
                    $this->askEmail($bool);
                }
            });
        }
        $this->ask('Por último, necesitamos su email.', function(Answer $answer) {
            /*$contact = new Contact(); $contact->name = $this->firstname; $contact->phone = $this->phone; $contact->mail = $this->email; $contact->save();*/
            $answer->getText();
            if(\preg_match("/^(([^<>()\[\]\.,;:\s@\”]+(\.[^<>()\[\]\.,;:\s@\”]+)*)|(\”.+\”))@(([^<>()[\]\.,;:\s@\”]+\.)+[^<>()[\]\.,;:\s@\”]{2,})$/", $answer)){
                $this->email = $answer->getText();
                $this->contacto = Contact::create([
                    'name'=> $this->firstname,
                    'phone'=> $this->phone,
                    'mail'=> $this->email
                ]);
                $this->say('Gracias '.$this->firstname);
                $bool = 0;
                $this->test($bool);
            } else{
                $bool = 1;
                $this->askEmail($bool);
            }
            }
        );
    }

    public function test($bool){
        if($bool == 1){
            $preguntaInicial = new BotResponse("Bienvenido! Qué desea saber?", [
                new ChatButton("Tengo bastante plastico pero no se en donde dejarlo, que debo hacer con el?", new BotResponse("Puedes dejarlo en un punto limpio para reciclarlo!")),

            ], true);
    
            $this->create_question($preguntaInicial, $preguntaInicial, $bool);
        }else{
             $preguntaInicial = new BotResponse("Bienvenido! Qué desea saber?", [
            new ChatButton("¿En que consiste la empresa?", new BotResponse("Somos una empresa que busca mantener una relación armónica entre las personas, 
            la sociedad y la naturaleza, para contribuir a una mejor calidad de vida.")),
            new ChatButton("¿Qué tipo de servicios ofrecen?", new BotResponse("Brindamos soluciones ambientales, para la gestión integral de residuos.")),
            new ChatButton("Desea cotizar algun servicio que ofrecemos?", new BotResponse("OK! Qué servicio desea cotizar?",[
                new ChatButton("Gestión de residuos", new BotResponse("Ingrese a este link: https://biobiorecicla.cl/cotizacion-empresas-instituciones/", null, true)),
                new ChatButton("Puntos limpios", new BotResponse("Ingrese a este link: https://biobiorecicla.cl/condominios-comunidades/", null, true)),
                new ChatButton("Consultoría", new BotResponse("Ingrese a este link: https://biobiorecicla.cl/cotizacion-empresas-instituciones/", null, true)),
                new ChatButton("Educación Ambiental", new BotResponse("Ingrese a este link: https://biobiorecicla.cl/condominios-comunidades/", null, true)),
                new ChatButton("Biciclaje", new BotResponse("Ingrese a este link: https://biobiorecicla.cl/conciencia-ambiental/", null, true))
            ]))
        ], true);

        $this->create_question($preguntaInicial, $preguntaInicial, $bool);
        }
       
    }
    

    public function create_question(BotResponse $botResponse, BotResponse $rootResponse, $bool){
        array_push($this->Responses, $botResponse->text);
        if($botResponse->save) $this->save_history($bool);

        if($botResponse->buttons == null){
            $this->say($botResponse->text);
            $this->create_question($rootResponse, $rootResponse, $bool);
            return;
        }        

        $question = Question::create($botResponse->text)//le preguntamos al usuario que quiere saber
            ->fallback('Unable to ask question')
            ->callbackId('ask_reason')
            ->addButtons(array_map( function($value){ return Button::create($value->text)->value($value->text);}, $botResponse->buttons ));
        //array_push($this->Responses, $botResponse->text);

        return $this->ask($question, function (Answer $answer) use ($botResponse, $rootResponse, $bool){
            if ($answer->isInteractiveMessageReply()) {

                $foundButtons = array_filter($botResponse->buttons, function($value, $key)  use($answer){
                    return $value->text == $answer->getValue();
                }, ARRAY_FILTER_USE_BOTH);

                if(count($foundButtons) > 0){
                    $foundButton = array_shift($foundButtons);
                        
                    array_push($this->Responses, $foundButton->text);
                    if($botResponse->save) $this->save_history($bool);
                    $this->create_question($foundButton->botResponse, $rootResponse, $bool);
                }
            }
        });
    }

    public function save_history($bool){
        if($bool == 0){
            $contactos = json_decode($this->contacto, true);
            $array_merge = array_merge($this->Responses, $contactos);
            $Responsejson = json_encode($array_merge);
            Storage::disk('public')->put('history '.$this->contacto->id.'.json', $Responsejson);
        }else{
            $contactos = json_decode($this->contacto, true);
            $array_merge = array_merge($this->Responses, $contactos);
            $Responsejson = json_encode($array_merge);
            Storage::disk('public')->put('history anonymous '.$this->contacto->id.'.json', $Responsejson);
        }
    }

    /*public function create_question($preguntita, $respuestita){
        $question = Question::create($preguntita)//le preguntamos al usuario que quiere saber
            ->fallback('Unable to ask question')
            ->callbackId('ask_reason')
            ->addButtons([
                Button::create($respuestita)->value('respuesta'),//Opción de hora, con value hour
            ]);
            return $this->ask($question, function (Answer $answer) {
                if ($answer->isInteractiveMessageReply()) {
                    if ($answer->getValue() === 'respuesta') {//Le muestra la hora la usuario si el value es hour
                        $this->say();
                    }else if ($answer->getValue() === 'day'){//Le muestra la hora la usuario si el value es date
                        $this->say('Brindamos soluciones ambientales, para la gestión integral de residuos.');
                        $click2 = '¿Que tipo de servicios ofrecen?';
                        $contactos = json_decode($this->contacto, true);
                        //array_push($this->Responses, $contactos, $this->click1, $click2);
                        array_push($this->Responses, $this->click1, $click2);
                        $array_merge = array_merge($this->Responses, $contactos);
                        $Responsejson = json_encode($array_merge);
                        //echo $Responsejson;
                        Storage::disk('public')->put('history '.$this->contacto->id.'.json', $Responsejson);
                    }
                }
            });

            json:

            {
                "texto": "what is this?",
                "buttons": [
                    0: {
                        "respuesta": "hola?",
                        "desencadena": {
                            "texto": "kfsdkfds",
                            "buttons": [
                                ...
                            ]
                        }
                    },
                    1: {
                        "respuesta": "chao?",
                        "desencadena": {
                            "texto": "Wenisima"
                        }
                    }
                ]
            }
            

            array objetoPregunta[];

            Objeto pregunta:
            - Texto de pregunta : string
            - Botones : array

            Objeto respuesta humana:
            - Texto : ?string
            - Boton

            Boton:
            - Texto de respuesta de usuario : string
            - Lo que desencadena (Respuesta bot / Respuesta bot con pregunta) (Objeto respuesta  / Objeto pregunta) : objeto bot

            Objeto respuesta 
            - Texto : string

            Objeto bot:
            - Heredan:
                - Objeto pregunta
                - Objeto respuesta

        
            Hola pregunte nomas // pregunta
            - Boton 1 // respuesta usuario
                - Bot responde la pregunta con texto / listo entonces volver al principio // respuesta bot
            - Boton 2 // respuesta usuario
                - Bot pregunta // respuesta bot - pregunta
                - Boton 1 // respuesta usuario
                    - Finalmente te responde / listo entonces volver al principio // respuesta bot
                - Boton 2 // respuesta usuario
                    - Bot pregunta // respuesta bot - pregunta
                    - Tu responder con texto // respuesta usuario
                        - Ok, gracias / listo entonces volver al principio // respuesta bot
            - n botones
            

            
    }*/

    /**
     * Start the conversation
     */
    public function run()
    {
        $this->askName();
    }
}
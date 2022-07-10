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
    protected $firstname;
    protected $phone;
    protected $email;
    
    /**
     * Start the conversation
     */
    public function init()
    {      
        $this->start_flow_from_json('officialchatflow');
        return;
        $s1 = ConversationFlow::remove_accents("concepcin");
        $s2 = ConversationFlow::remove_accents("san pedro");        

        $tok = new WhitespaceTokenizer();
        $J = new JaccardIndex();
        $cos = new CosineSimilarity();
        $simhash = new Simhash(16); // 16 bits hash

        $setA = $tok->tokenize($s1);
        $setB = $tok->tokenize($s2);

        $this->say((string)$J->similarity(
            $setA,
            $setB
        ));
        $this->say((string)$cos->similarity(
            $setA,
            $setB
        ));
        $this->say((string)$simhash->similarity(
            $setA,
            $setB
        ));
        $this->say((string)$simhash->simhash($setA));
        $this->say((string)$simhash->simhash($setB));
        

        return;
        $this->start_flow_from_json('officialchatflow');
        return;
        // $this->codeVersion();
    }

    protected $puntosLimpios;

    protected function getPuntoLimpio($ubicacion, $tipo) : array {
        $toReturn = array_filter($this->puntosLimpios, function(PuntoLimpio $item) use ($ubicacion, $tipo){
            if($ubicacion != null && $tipo == null) return $item->comuna == $ubicacion;
            if($ubicacion == null && $tipo != null) return in_array($tipo, $item->tipos, true);
            return $item->comuna == $ubicacion && in_array($tipo, $item->tipos, true);
        });
        return $toReturn;
    }

    public function codeVersion(){

        $this->puntosLimpios = [
            new PuntoLimpio('punto1', 'null', 'null', '-36,781836', '-73,074929', ['Plasticos', 'Aceite'], 'Municipalidad Concepción', 'Concepcion'),
            new PuntoLimpio('punto2', 'null', 'null', '-36,781836', '-73,074929', ['Escombros', 'Textiles', 'Metales'], 'Sodimac', 'Concepcion'),
            new PuntoLimpio('punto3', 'Victor Lamas', '567', '-36,8328', '-73,0476', ['Papel', 'Carton', 'Latas', 'Botellas'], 'Municipalidad Concepción', 'Concepcion'),
            new PuntoLimpio('punto4', 'Av.Collao', '1202 casilla 5-C', '-36,81115', '-73,01583', ['Papel', 'Carton', 'Latas', 'Botellas PET'], 'Municipalidad Concepción', 'Concepcion'),
            new PuntoLimpio('punto5', 'Av. Los Carrera', '301', '-36,80494', '-73,06167', ['Papel', 'Carton', 'Botellas PET', 'Latas', 'Envases Tetrapak'], 'Sodimac', 'Concepcion'),
            new PuntoLimpio('punto6', 'Victor Lamas', '1290 casilla 160-C', '-36,82925', '-73,03429', ['Latas', 'Plasticos', 'Papel'], 'Universidad de Concepcion', 'Concepcion'),
            new PuntoLimpio('punto7', 'Arturo Prat', '879', '-36,82603', '-73,06154', ['Latas', 'Plasticos', 'Papel'], 'Universidad  Santo Tomas', 'Concepcion'),
            new PuntoLimpio('punto8', 'Av. Los Carrera', '301', '-36,82872', '-73,06361', ['Papel', 'Carton', 'Botellas PET', 'Latas', 'Envases Tetrapak'], 'Tottus', 'Concepcion'),
            new PuntoLimpio('punto9', 'Av. Costanera Andalien', '336', '-36,77542', '-73,04407', ['Papel', 'Carton', 'Botellas', 'Vidrio', 'Aceite'], 'Municipalidad Concepción', 'Concepcion'),
            new PuntoLimpio('punto10', 'Av. Ejercito', '330', '-36,77611', '-73,08949', ['Botellas PET'], 'Municipalidad Talcahuano', 'Talcahuano'),
            new PuntoLimpio('punto11', 'Las Hortensias', '4850', '-36,7556', '-73,09271', ['Botellas PET'], 'Municipalidad Talcahuano', 'Talcahuano'),
            new PuntoLimpio('punto12', 'Las Garzas', '193', '-36,72049', '-73,14115', ['Botellas PET'], 'Municipalidad Talcahuano', 'Talcahuano'),
            new PuntoLimpio('punto13', '28 de octubre', '205', '-36,71104', '-73,11824', ['Botellas PET'], 'Municipalidad Talcahuano', 'Talcahuano'),
            new PuntoLimpio('punto14', 'Almte. Neff', '270', '-36,74669', '-73,09157', ['Botellas PET'], 'Municipalidad Talcahuano', 'Talcahuano'),
            new PuntoLimpio('punto15', 'Desiderio Garcia', '979', '-36,75158', '-73,10564', ['Botellas PET'], 'Municipalidad Talcahuano', 'Talcahuano'),
            new PuntoLimpio('punto16', 'Av. Colón', '3260', '-36,74265', '-73,09816', ['Botellas PET'], 'null', 'Talcahuano'),
            new PuntoLimpio('punto17', 'Sgto. Aldea', '121', '-36,71406', '-73,11611', ['Botellas PET'], 'Municipalidad Talcahuano', 'Talcahuano'),
            new PuntoLimpio('punto18', 'A', '909', '-36,77664', '-73,07323', ['Botellas PET', 'Papel', 'Cartón', 'Aceite vegetal'], 'Municipalidad Talcahuano', 'Talcahuano'),
            new PuntoLimpio('punto19', 'Colo colo', '249', '-36,83774', '-73,09316', ['Latas', 'Papeles', 'Cartones'], 'Municipalidad San pedro de la paz', 'San Pedro'),
            new PuntoLimpio('punto20', 'Los fresnos', '4130000', '-36,847141', '-73,104942', ['Latas', 'Papeles', 'Cartones'], 'Municipalidad San pedro de la paz', 'San Pedro'),
            new PuntoLimpio('punto21', 'Av. Manuel Rodriguez', '1045', '-36,93034', '-73,021767', ['Cartones', 'Papeles', 'Plasticos'], 'Municipalidad Chiguayante', 'Chiguayante'),
            new PuntoLimpio('punto22', 'Av. 8 Oriente', '720', '-36,906445', '-73,031436', ['Cartones', 'Papeles', 'Plasticos'], 'Municipalidad Chiguayante', 'Chiguayante'),
            new PuntoLimpio('punto23', 'Av.Gnrl Prats', '80', '-37,023102', '-73,153526', ['Cartones', 'Papeles', 'Plasticos'], 'Municipalidad Coronel', 'Coronel'),
        ];





        // Lista con preguntas persona natural
        $preguntasNatural = new BotResponse("Bienvenido! Qué desea saber?", [
            new ChatButton("Tengo bastante plastico pero no se en donde dejarlo, que debo hacer con el?", fn() => new BotResponse("Puedes dejarlo en un punto limpio para reciclarlo!")),
        ], true, null, true);

        // Lista con preguntas principales empresa
        $preguntasEmpresa = new BotResponse("Bienvenido! Qué desea saber?", [
            new ChatButton("¿En que consiste la empresa?", fn() => new BotResponse("Somos una empresa que busca mantener una relación armónica entre las personas, 
            la sociedad y la naturaleza, para contribuir a una mejor calidad de vida.")),
            new ChatButton("¿Qué tipo de servicios ofrecen?", fn() => new BotResponse("Brindamos soluciones ambientales, para la gestión integral de residuos.")),
            new ChatButton("Desea cotizar algun servicio que ofrecemos?", fn() => new BotResponse("OK! Qué servicio desea cotizar?",[
                new ChatButton("Gestión de residuos", fn() => new BotResponse("Ingrese a este link: https://biobiorecicla.cl/cotizacion-empresas-instituciones/", null, true)),
                new ChatButton("Puntos limpios", fn() => new BotResponse("Ingrese a este link: https://biobiorecicla.cl/condominios-comunidades/", null, true)),
                new ChatButton("Consultoría", fn() => new BotResponse("Ingrese a este link: https://biobiorecicla.cl/cotizacion-empresas-instituciones/", null, true)),
                new ChatButton("Educación Ambiental", fn() => new BotResponse("Ingrese a este link: https://biobiorecicla.cl/condominios-comunidades/", null, true)),
                new ChatButton("Biciclaje", fn() => new BotResponse("Ingrese a este link: https://biobiorecicla.cl/conciencia-ambiental/", null, true))
            ]))
        ], true);

        // Create email question. Will be used only with consent
        $emailQuestion = new BotOpenQuestion(
            'Por último necesitamos su email',
            null,
            // Check if answer is an email
            fn(Answer $answer) => preg_match(ConversationFlow::email_regex(), $answer),
            'El email debe estar en el formato de "tumail@dominio.com", intente de nuevo por favor',
            fn($answer) => $this->update_email_and_contact($answer->getText())
        );

        // Create phone question. Will be used only with consent
        $phoneQuestion = new BotOpenQuestion(
            'Cuál es su número de teléfono?',
            // If is a phone number, go to "emailQuestion"
            fn() => $emailQuestion,
            // Check if answer is phone number
            fn(Answer $answer) => preg_match(ConversationFlow::phone_regex(), $answer),
            // When is not a phone number, display this error message
            'El número debe estar en el formato de "+56912345678", intente de nuevo por favor',
            fn($answer) => $this->phone = $answer->getText()
        );

        // Create question: Is business?
        $businessQuestion = new BotResponse(
            'Es usted una persona natural o una empresa?',
            [
                new ChatButton(
                    'Persona natural', 
                    // Go to "preguntas natural"
                    fn() => $preguntasNatural,
                    [],
                    fn() => $this->conversationFlow->set_user_section(HUMAN)
                ),
                new ChatButton(
                    'Empresa', 
                    // If is business, then ask about consent. 
                    // So if accepts, start with phone question. Otherwise, skip to "preguntasEmpresa"
                    fn() => new BotResponse(
                        $this->firstname.', esta usted de acuerdo con que nos proporcione su número de teléfono y email para que podamos contactarlo para una atención mas personalizada?',
                        [
                            new ChatButton('Si, me parece bien', fn() => $phoneQuestion),
                            new ChatButton('No estoy de acuerdo', fn() => $preguntasEmpresa)
                        ]
                    ),
                    [],
                    fn() => $this->conversationFlow->set_user_section(BUSINESS)
                )
            ]
        );

        // Create name question
        $nameQuestion = new BotOpenQuestion(
            'Cuál es su nombre?',
            fn() => new BotReply(
                'Un placer conocerle '.$this->firstname,
                fn() => $businessQuestion,
                [],
                null,
                false,
                3
            ),
            null, null, fn(Answer $answer) => $this->firstname = $answer->getText()
        );

        // Start with "name question"
        $this->start_flow($nameQuestion, $preguntasEmpresa);
    }

    protected function update_email_and_contact(string $newEmail){
        $this->email = $newEmail;

        // Get current used contact and update data
        $this->conversationFlow->update_contact(
            $this->firstname, 
            $this->phone,
            $this->email,
            true
        );
    }
}


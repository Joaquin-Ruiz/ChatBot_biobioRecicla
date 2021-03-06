<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class FlowDemo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flow:demo';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'ChatFlow';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Storage::disk('jsonchatflows')->put('officialchatflow.json', $this->jsondemocontent());
       
        echo "Json test demo created \n";
        echo 'To test, use "$this->start_flow_from_json(\'officialchatflow\');" in your BotConversation';
        return 0;
    }

    public function jsondemocontent(){
        return '{
            "name": "Testing ChatFlow Json",
            "responses": {
              "start": {
                "type": "BotOpenQuestion",
                "text": "Cuál es su nombre?",
                "saveAnswerKey": "name",
                "nextResponse": {
                  "type": "BotReply",
                  "text": "Mucho gusto $name",
                  "nextResponse": "businessQuestion"
                }
              },
              "businessQuestion": {
                "type": "BotResponse",
                "text": "Es usted una persona natural o una empresa?",
                "buttons": [
                  {
                    "text": "Persona natural",
                    "nextResponse": "preguntasNatural"
                  },
                  {
                    "text": "Empresa",
                    "nextResponse": {
                      "type": "BotResponse",
                      "text": "$name, esta usted de acuerdo con que nos proporcione su número de teléfono y email para que podamos contactarlo para una atención mas personalizada?",
                      "buttons": [
                        {
                          "text": "Si, me parece bien",
                          "nextResponse": "phoneQuestion"
                        },
                        {
                          "text": "No estoy de acuerdo",
                          "nextResponse": "preguntasEmpresa"
                        }
                      ]
                    }
                  }
                ]
              },
              "phoneQuestion":{
                "type": "BotOpenQuestion",
                "text": "Cuál es su número de teléfono?",
                "nextResponse": "emailQuestion",
                "validationRegex": "phone",
                "saveAnswerKey": "phone",
                "errorMessage": "El número debe estar en el formato de \"+56912345678\", intente de nuevo por favor"
              },
              "emailQuestion":{
                "type": "BotOpenQuestion",
                "text": "Por último necesitamos su email",
                "nextResponse": "preguntasEmpresa",
                "validationRegex": "email",
                "saveAnswerKey": "email",
                "trySaveContactData": true,
                "errorMessage": "El email debe estar en el formato de \"tumail@dominio.com\", intente de nuevo por favor"
              },
              "preguntasNatural": {
                "type": "BotResponse",
                "text": "Bienvenido! Qué desea saber?",
                "autoRoot": true,
                "buttons": [
                  {
                    "text": "Tengo bastante plastico pero no se en donde dejarlo, que debo hacer con el?",
                    "nextResponse": {
                      "type": "BotReply",
                      "text": "Puedes dejarlo en un punto limpio para reciclarlo!"
                    }
                  }
                ]
              },
              "preguntasEmpresa":{
                "type": "BotResponse",
                "text": "Bienvenido! Qué desea saber?",
                "autoRoot": true,
                "buttons": [
                  {
                    "text": "¿En que consiste la empresa?",
                    "nextResponse": {
                      "type": "BotReply",
                      "text": "Somos una empresa que busca mantener una relación armónica entre las personas, la sociedad y la naturaleza, para contribuir a una mejor calidad de vida."
                    }
                  },
                  {
                    "text": "¿Qué tipo de servicios ofrecen?",
                    "nextResponse": {
                      "type": "BotReply",
                      "text": "Brindamos soluciones ambientales, para la gestión integral de residuos."
                    }
                  },
                  {
                    "text": "Desea cotizar algun servicio que ofrecemos?",
                    "nextResponse": {
                      "type":"BotResponse",
                      "text": "OK! Qué servicio desea cotizar?",
                      "buttons": [
                        {
                          "text": "Gestión de residuos",
                          "nextResponse": {
                            "type": "BotReply",
                            "text": "Ingrese a este link: https://biobiorecicla.cl/cotizacion-empresas-instituciones/"
                          }
                        },
                        {
                          "text": "Puntos limpios",
                          "nextResponse": {
                            "type": "BotReply",
                            "text": "Ingrese a este link: https://biobiorecicla.cl/condominios-comunidades/"
                          }
                        },
                        {
                          "text": "Consultoría",
                          "nextResponse": {
                            "type": "BotReply",
                            "text": "Ingrese a este link: https://biobiorecicla.cl/cotizacion-empresas-instituciones/"
                          }
                        },
                        {
                          "text": "Educación Ambiental",
                          "nextResponse": {
                            "type": "BotReply",
                            "text": "Ingrese a este link: https://biobiorecicla.cl/condominios-comunidades/"
                          }
                        },
                        {
                          "text": "Biciclaje",
                          "nextResponse": {
                            "type": "BotReply",
                            "text": "Ingrese a este link: https://biobiorecicla.cl/conciencia-ambiental/"
                          }
                        }
                      ]
                    }
                  }
                ]
              }
            }
          }';
    }
}

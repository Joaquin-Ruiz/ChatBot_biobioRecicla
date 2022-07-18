<?php

namespace App\Classes\BioioRecicla;

class PuntoLimpio{

    public $nombre;
    public $calle;
    public $numero;
    public $latitud;
    public $longitud;
    public $residuos;
    public $administracion;
    public $comuna;

    public function __construct($nombre, $calle, $numero, $latitud, $longitud, $residuos, $administracion, $comuna)
    {
        $this->nombre = $nombre;
        $this->calle = $calle;
        $this->numero = $numero;
        $this->latitud = $latitud;
        $this->longitud = $longitud;
        $this->residuos = $residuos;
        $this->administracion = $administracion;
        $this->comuna = $comuna;
    }

}
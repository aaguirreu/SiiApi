<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\V1\DteController;
use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PasarelaController extends DteController
{
    public function __construct($tipos_dte)
    {
        self::$tipos_dte = $tipos_dte;
        self::isToken();
    }

    /**
     * Recorre los documentos como array y les asigna un folio
     */
    protected function parsearDocumentos($dte): array
    {
        $documentos = [];
        $dte = json_decode(json_encode($dte), true);

        foreach ($dte["Documentos"] as $documento) {
            $modeloDocumento = $documento;

            if(!isset($modeloDocumento["Encabezado"]["IdDoc"]["TipoDTE"]))
                return ["error" => "Debe indicar el TipoDTE"];

            if(!isset($modeloDocumento["Encabezado"]["IdDoc"]["Folio"]))
                return ["error" => "Debe indicar el Folio"];
        }
        return $documentos;
    }
}

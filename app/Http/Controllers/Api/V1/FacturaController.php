<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use CURLFile;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use sasco\LibreDTE\Estado;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\Sii\Autenticacion;
use sasco\LibreDTE\Sii\ConsumoFolio;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\Sii\Folios;
use sasco\LibreDTE\XML;
use SimpleXMLElement;

class FacturaController extends DteController
{
    public function __construct($tipos_dte)
    {
        self::$tipos_dte = $tipos_dte;
    }
    protected function enviar($rut_envia, $rut_emisor, $dte)
    {
        $token = json_decode(file_get_contents(base_path('config.json')))->token_dte;
        // enviar DTE
        // Set ambiente producción
        Sii::setAmbiente(Sii::CERTIFICACION);
        $result = Sii::enviar($rut_envia, $rut_emisor, $dte, $token);

        // si hubo algún error al enviar al servidor mostrar
        if ($result===false) {
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return $errores;
        }

        // Mostrar resultado del envío
        if ($result->STATUS!='0') {
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return $errores;
        }
        return $result->asXML();
    }

    // Borrar cuando deje de utilizar ambiente certificacion
    protected function getTokenDte() {
        // Set ambiente producción
        //Sii::setAmbiente(Sii::PRODUCCION);
        $token = Autenticacion::getToken($this->obtenerFirma());
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $config_file->token_dte = $token;
        $config_file->token_dte_timestamp = Carbon::now('America/Santiago')->timestamp;;
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    protected function generarEnvioDteXml(array $factura, FirmaElectronica $Firma, array $Folios, array $caratula)
    {
        // generar XML del DTE timbrado y firmado
        foreach ($factura as $documento) {
            $DTE = new Dte($documento);
            $DTE->timbrar($Folios[$DTE->getTipo()]);
            $DTE->firmar($Firma);
        }

        // generar sobre con el envío del DTE y enviar al SII
        $EnvioDTE = new EnvioDte();
        $EnvioDTE->agregar($DTE);
        $EnvioDTE->setCaratula($caratula);
        $EnvioDTE->setFirma($Firma);
        $EnvioDTE->generar();
        $EnvioDTExml = new XML();
        if ($EnvioDTE->schemaValidate()) {
            $EnvioDTExml = $EnvioDTE->generar();
        } else {
            // si hubo errores mostrar
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return $errores;
        }
        return $EnvioDTExml;
    }

    protected function parseDte($dte): array
    {
        $boletas = [];
        foreach ($dte->Boletas as $boleta) {
            // Modelo boleta
            $modeloBoleta = [
                "Encabezado" => [
                    "IdDoc" => [],
                    "Emisor" => [
                        'RUTEmisor' => $boleta->Encabezado->Emisor->RUTEmisor,
                        'RznSoc' => $boleta->Encabezado->Emisor->RznSoc,
                        'GiroEmis' => $boleta->Encabezado->Emisor->GiroEmis,
                        'DirOrigen' => $boleta->Encabezado->Emisor->DirOrigen,
                        'Acteco' => $boleta->Encabezado->Emisor->Acteco,
                        'CmnaOrigen' => $boleta->Encabezado->Emisor->CmnaOrigen,
                        //'CiudadOrigen' => $boleta->Encabezado->Emisor->CiudadOrigen,
                        //'CdgVendedor' => $boleta->Encabezado->Emisor->CdgVendedor,
                    ],
                    "Receptor" => [
                        'RUTRecep' => $boleta->Encabezado->Receptor->RUTRecep,
                        'RznSocRecep' => $boleta->Encabezado->Receptor->RznSocRecep,
                        //'GiroRecep' => $boleta->Encabezado->Receptor->GiroRecep,
                        'DirRecep' => $boleta->Encabezado->Receptor->DirRecep,
                        'CmnaRecep' => $boleta->Encabezado->Receptor->CmnaRecep,
                        //'CiudadRecep' => $boleta->Encabezado->Receptor->CiudadRecep,
                    ],
                ],
                "Detalle" => [],
                "Referencia" => [],
            ];

            $detallesExentos = [];
            $detallesAfectos = [];

            foreach ($boleta->Detalle as $detalle) {
                if (array_key_exists("IndExe", json_decode(json_encode($detalle), true))) {
                    $detallesExentos[] = json_decode(json_encode($detalle), true);
                } else {
                    $detallesAfectos[] = json_decode(json_encode($detalle), true);
                }
            }

            if (!empty($detallesExentos)) {
                $modeloBoletaExenta = $this->generarModeloBoleta($modeloBoleta, $detallesExentos, 34);
                $boletas[] = $modeloBoletaExenta;
            }

            if (!empty($detallesAfectos)) {
                $modeloBoletaAfecta = $this->generarModeloBoleta($modeloBoleta, $detallesAfectos, 33);
                $boletas[] = $modeloBoletaAfecta;
            }
        }

        // Compara si el número de folios restante en el caf es mayor o igual al número de documentos a enviar
        foreach (self::$tipos_dte as $key) {
            $folios_restantes = DB::table('caf')->where('folio_id', '=', $key)->latest()->first()->folio_final - DB::table('folio')->where('id', '=', $key)->latest()->first()->cant_folios;
            $folios_boletas = self::$folios[$key] - self::$folios_inicial[$key] + 1;
            if ($folios_boletas > $folios_restantes) {
                $response[] = [
                    'error' => 'No hay folios suficientes para generar los documentos',
                    'tipo_folio' => $key,
                    'folios_restantes' => $folios_restantes,
                    'folios_boletas' => $folios_boletas,
                ];
            }
        }

        return $response ?? $boletas;
    }

    protected function obtenerCaratula($dte): array
    {
        return [
            'RutEnvia' => $dte->Caratula->RutEnvia, // se obtiene automáticamente de la firma
            'RutReceptor' => $dte->Caratula->RutReceptor, // se obtiene automáticamente
            'FchResol' => $dte->Caratula->FchResol,
            'NroResol' => $dte->Caratula->NroResol,
        ];
    }
}



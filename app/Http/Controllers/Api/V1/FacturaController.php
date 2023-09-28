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
    protected function enviar2($rut_envia, $rut_emisor, $dte)
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

    protected function enviar($usuario, $empresa, $dte)
    {
        $token = json_decode(file_get_contents(base_path('config.json')))->token_dte;
        // definir datos que se usarán en el envío
        list($rutSender, $dvSender) = explode('-', str_replace('.', '', $usuario));
        list($rutCompany, $dvCompany) = explode('-', str_replace('.', '', $empresa));
        if (strpos($dte, '<?xml') === false) {
            $dte = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $dte;
        }
        do {
            if (!file_exists(env('DTES_PATH', "")."EnvioFACTURA")) {
                mkdir(env('DTES_PATH', "")."EnvioFACTURA", 0777, true);
            }
            $filename = 'EnvioFACTURA_'.$this->timestamp.'.xml';
            $filename = str_replace(' ', 'T', $filename);
            $filename = str_replace(':', '-', $filename);
            $file = env('DTES_PATH', "")."EnvioFACTURA\\".$filename;
        } while (file_exists($file));

        // NO GUARDA LOS ARCHIVOS A PESAR DE QUE RETORNA QUE SI
        // Guardar xml en disco
        if(!Storage::disk('dtes')->put('EnvioFACTURA\\'.$filename, $dte)){
            return response()->json([
                'message' => 'Error al guardar el DTE en el servidor',
            ], 400);
        }

        $data = [
            'rutSender' => $rutSender,
            'dvSender' => $dvSender,
            'rutCompany' => $rutCompany,
            'dvCompany' => $dvCompany,
            'archivo' => new CURLFile($file),
        ];

        $header = [
            'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Logiciel)',
            'Cookie: TOKEN=' . $token];

        // crear sesión curl con sus opciones
        $curl = curl_init();
        //$url = 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload'; // certificacion
        $url = 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload'; // producción
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        // enviar XML al SII
        for ($i=0; $i<self::$retry; $i++) {
            $response = curl_exec($curl);
            if ($response and $response!='Error 500') {
                break;
            }
        }

        // verificar respuesta del envío y entregar error en caso que haya uno
        if (!$response or $response=='Error 500') {
            if (!$response) {
                Log::write(Estado::ENVIO_ERROR_CURL, Estado::get(Estado::ENVIO_ERROR_CURL, curl_error($curl)));
            }
            if ($response=='Error 500') {
                Log::write(Estado::ENVIO_ERROR_500, Estado::get(Estado::ENVIO_ERROR_500));
            }
            // Borrar xml guardado anteriormente
            Storage::disk('dtes')->delete('EnvioFACTURA\\'.$filename);
            return response()->json([
                'message' => 'Error al enviar el DTE al SII',
                'error' => $response,
            ], 400);
        }

        // cerrar sesión curl
        curl_close($curl);
        // crear XML con la respuesta y retornar
        try {
            $xml = new \SimpleXMLElement($response, LIBXML_COMPACT);
        } catch (Exception $e) {
            Log::write(Estado::ENVIO_ERROR_XML, Estado::get(Estado::ENVIO_ERROR_XML, $e->getMessage()));
            return false;
        }
        if ($xml->STATUS!=0) {
            Log::write(
                $xml->STATUS,
                Estado::get($xml->STATUS).(isset($xml->DETAIL)?'. '.implode("\n", (array)$xml->DETAIL->ERROR):'')
            );
        }

         // Convertir a array asociativo
        $arrayData = json_decode(json_encode($xml), true);

        // Respuesta como JSON
        $json_response = json_decode(json_encode($arrayData, JSON_PRETTY_PRINT));

        // Guardar envio dte en la base de datos
        $this->guardarEnvioDte($json_response, $filename);

        return $json_response;
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
        $EnvioDTE = new EnvioDte();
        foreach ($factura as $documento) {
            $DTE = new Dte($documento);
            if (!$DTE->timbrar($Folios[$DTE->getTipo()]))
                break;
            if (!$DTE->firmar($Firma))
                break;
            $EnvioDTE->agregar($DTE);
        }
        // generar sobre con el envío del DTE y enviar al SII
        $EnvioDTE->setCaratula($caratula);
        $EnvioDTE->setFirma($Firma);
        $EnvioDTExml = $EnvioDTE->generar();
        if ($EnvioDTE->schemaValidate()) {
            return $EnvioDTExml;
        } else {
            // si hubo errores mostrar
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return $errores;
        }
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
                        'Acteco' => $boleta->Encabezado->Emisor->Acteco,
                        'DirOrigen' => $boleta->Encabezado->Emisor->DirOrigen,
                        'CmnaOrigen' => $boleta->Encabezado->Emisor->CmnaOrigen,
                        'CiudadOrigen' => $boleta->Encabezado->Emisor->CiudadOrigen,
                        'CdgVendedor' => $boleta->Encabezado->Emisor->CdgVendedor,
                    ],
                    "Receptor" => [
                        'RUTRecep' => $boleta->Encabezado->Receptor->RUTRecep,
                        'RznSocRecep' => $boleta->Encabezado->Receptor->RznSocRecep,
                        'GiroRecep' => $boleta->Encabezado->Receptor->GiroRecep,
                        'DirRecep' => $boleta->Encabezado->Receptor->DirRecep,
                        'CmnaRecep' => $boleta->Encabezado->Receptor->CmnaRecep,
                        'CiudadRecep' => $boleta->Encabezado->Receptor->CiudadRecep,
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
            //'RutReceptor' => $dte->Caratula->RutReceptor, // se obtiene automáticamente
            'FchResol' => $dte->Caratula->FchResol,
            'NroResol' => $dte->Caratula->NroResol,
        ];
    }
}



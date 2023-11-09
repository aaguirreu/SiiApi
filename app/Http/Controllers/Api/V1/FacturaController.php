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

    protected function enviar($usuario, $empresa, $dte)
    {
        $token = json_decode(file_get_contents(base_path('config.json')))->token;
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
        //$dte = mb_convert_encoding($dte, "UTF-8", "auto");

        if(!Storage::disk('dtes')->put('EnvioFACTURA\\'.$filename, $dte)){
            return response()->json([
                'message' => 'Error al guardar el DTE en el servidor',
            ], 400);
        }

        //$file = Storage::disk('dtes')->get('EnvioFACTURA\\'.$filename);
        //$file = mb_convert_encoding($filename, "ISO-8859-1", "auto");

        //$file= 'C:\Users\aagui\Downloads\factura_ejemplo.xml';

        $data = [
            'rutSender' => $rutSender,
            'dvSender' => $dvSender,
            'rutCompany' => $rutCompany,
            'dvCompany' => $dvCompany,
            'archivo' => new CURLFile($file, 'text/xml', $filename),
        ];

        $header = [
            'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Logiciel)',
            'Cookie: TOKEN=' . $token,
            'Content-Type: text/html; charset=ISO-8859-1',
        ];

        // crear sesión curl con sus opciones
        $curl = curl_init();
        $url = 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload'; // certificacion
        //$url = 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload'; // producción
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
            $arrayData = json_decode(json_encode($xml), true);
            // Borrar xml guardado anteriormente
            Storage::disk('dtes')->delete('EnvioFACTURA\\'.$filename);
            return json_decode(json_encode($arrayData, JSON_PRETTY_PRINT));
        }

         // Convertir a array asociativo
        $arrayData = json_decode(json_encode($xml), true);

        // Respuesta como JSON
        $json_response = json_decode(json_encode($arrayData, JSON_PRETTY_PRINT));

        return [$json_response, $filename];
    }

    // Borrar cuando deje de utilizar ambiente certificacion
    protected function getTokenDte()
    {
        // Set ambiente producción
        //Sii::setAmbiente(Sii::PRODUCCION);
        $token = Autenticacion::getToken($this->obtenerFirma());
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $config_file->token = $token;
        $config_file->token_timestamp = Carbon::now('America/Santiago')->timestamp;;
        //Storage::disk('local')->put('config.json', json_encode($config_file, JSON_PRETTY_PRINT));
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    protected function parseDte($dte): array
    {
        $documentos = [];
        $dte = json_decode(json_encode($dte), true);

        foreach ($dte["Documentos"] as $documento) {
            /*$modeloDocumento = [
                "Encabezado" => [
                    "IdDoc" => $documento["Encabezado"]["IdDoc"] ?? [],
                    "Emisor" => $documento["Encabezado"]["Emisor"] ?? [],
                    "Receptor" => $documento["Encabezado"]["Receptor"] ?? [],
                    "Totales" => $documento["Encabezado"]["Totales"] ?? [],
                    ],
                "Detalle" => $documento["Detalle"] ?? [],
                "Referencia" => $documento["Referencia"] ?? false,
                "DscRcgGlobal" => $documento["DscRcgGlobal"] ?? false,
            ];*/
            $modeloDocumento = $documento;

            if(!isset($modeloDocumento["Encabezado"]["IdDoc"]["TipoDTE"]))
                return ["error" => "Debe indicar el TipoDTE"];

            $tipoDte = $modeloDocumento["Encabezado"]["IdDoc"]["TipoDTE"];

            $modeloDocumento["Encabezado"]["IdDoc"]["Folio"] = ++self::$folios[$tipoDte];
            $documentos[] = $modeloDocumento;
        }

        // Compara si el número de folios restante en el caf es mayor o igual al número de documentos a enviar
        foreach (self::$folios as $key => $value) {
            $folio_final = DB::table('caf')->where('folio_id', '=', $key)->latest()->first()->folio_final;
            $cant_folio = DB::table('secuencia_folio')->where('id', '=', $key)->latest()->first()->cant_folios;
            $folios_restantes = $folio_final - $cant_folio;
            $folios_documentos = self::$folios[$key] - self::$folios_inicial[$key] + 1;
            if ($folios_documentos > $folios_restantes) {
                $response = [
                    'error' => 'No hay folios suficientes para generar los documentos',
                    'tipo_folio' => $key,
                    'máxmimo_rango_caf' => $folio_final,
                    'último_folio_utilizado' => $cant_folio,
                    'folios_restantes' => $folios_restantes,
                    'folios_a_utilizar' => $folios_documentos,
                ];
            }
        }

        return $response ?? $documentos;
    }
}



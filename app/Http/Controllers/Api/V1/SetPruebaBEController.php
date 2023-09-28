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

class SetPruebaBEController extends DteController
{
    // Número de casos, solo para set de pruebas
    private static $casos;

    public function __construct($tipos_dte)
    {
        self::$tipos_dte = $tipos_dte;
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
            if (!file_exists(env('DTES_PATH', "")."EnvioBOLETA")) {
                mkdir(env('DTES_PATH', "")."EnvioBOLETA", 0777, true);
            }
            $filename = 'EnvioBOLETA_'.$this->timestamp.'.xml';
            $filename = str_replace(' ', 'T', $filename);
            $filename = str_replace(':', '-', $filename);
            $file = env('DTES_PATH', "")."EnvioBOLETA\\".$filename;
        } while (file_exists($file));
        Storage::disk('dtes')->put('EnvioBOLETA\\'.$filename, $dte);
        $data = [
            'rutSender' => $rutSender,
            'dvSender' => $dvSender,
            'rutCompany' => $rutCompany,
            'dvCompany' => $dvCompany,
            'archivo' => new CURLFile($file),
        ];
        $header = ['Cookie: TOKEN=' . $token];

        // crear sesión curl con sus opciones
        $curl = curl_init();
        $url = 'https://pangal.sii.cl/recursos/v1/boleta.electronica.envio';
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

        // cerrar sesión curl
        curl_close($curl);

        // verificar respuesta del envío y entregar error en caso que haya uno
        if (!$response or $response=='Error 500') {
            if (!$response) {
                Log::write(Estado::ENVIO_ERROR_CURL, Estado::get(Estado::ENVIO_ERROR_CURL, curl_error($curl)));
            }
            if ($response=='Error 500') {
                Log::write(Estado::ENVIO_ERROR_500, Estado::get(Estado::ENVIO_ERROR_500));
            }
            // Borrar xml guardado anteriormente
            Storage::disk('dtes')->delete('EnvioBOLETA\\'.$filename);
            return response()->json([
                'message' => 'Error al enviar el DTE al SII',
                'error' => $response,
            ], 400);
        }

        // crear json con la respuesta y retornar
        try {
            $json_response = json_decode($response);
        } catch (Exception $e) {
            Log::write(Estado::ENVIO_ERROR_XML, Estado::get(Estado::ENVIO_ERROR_XML, $e->getMessage()));
            echo $e;
            return false;
        }
        if (gettype($json_response) != 'object') {
            echo $response;
            Log::write(
                Estado::ENVIO_ERROR_XML,
                Estado::get(Estado::ENVIO_ERROR_XML, $response)
            );
        }

        // Guardar envio dte en la base de datos
        $this->guardarEnvioDte($json_response, $filename);

        return $response;
    }

    protected function enviarRcof(ConsumoFolio $ConsumoFolio, $dte_filename) {
        // Set ambiente certificacion
        Sii::setAmbiente(Sii::CERTIFICACION);

        // Enviar rcof
        $response = $ConsumoFolio->enviar(self::$retry);

        if($response != false){
            // Guardar xml en storage
            $filename = 'RCOF_'.$this->timestamp.'.xml';
            $filename = str_replace(' ', 'T', $filename);
            $filename = str_replace(':', '-', $filename);
            //$file = env('DTES_PATH', "")."EnvioBOLETA/".$filename;
            Storage::disk('dtes')->put('EnvioRcof\\'.$filename, $ConsumoFolio->generar());

            $dte_id = DB::table('envio_dte')->where('xml_filename', '=', $dte_filename)->latest()->first()->id;
            // Guardar en base de datos
            DB::table('envio_rcof')->insert([
                'id' => $dte_id,
                'trackid' => $response,
                'xml_filename' => $filename,
                'created_at' => $this->timestamp,
                'updated_at' => $this->timestamp
            ]);
        }
        return $response;
    }

    protected function parseDte($dte): array
    {
        // Contador Casos (número de documentos a enviar)
        // SOLO PARA SET DE PRUEBAS
        self::$casos = 1;

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
                        'CmnaOrigen' => $boleta->Encabezado->Emisor->CmnaOrigen,
                    ],
                    "Receptor" => [
                        'RUTRecep' => $boleta->Encabezado->Receptor->RUTRecep,
                        'RznSocRecep' => $boleta->Encabezado->Receptor->RznSocRecep,
                        'DirRecep' => $boleta->Encabezado->Receptor->DirRecep,
                        'CmnaRecep' => $boleta->Encabezado->Receptor->CmnaRecep,
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
                $modeloBoletaExenta = $this->generarModeloBoleta($modeloBoleta, $detallesExentos, 41);
                $boletas[] = $modeloBoletaExenta;
            }

            if (!empty($detallesAfectos)) {
                $modeloBoletaAfecta = $this->generarModeloBoleta($modeloBoleta, $detallesAfectos, 39);
                $boletas[] = $modeloBoletaAfecta;
            }
            self::$casos++;
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

    protected function generarModeloBoleta($modeloBoleta, $detalles, $tipoDTE): array
    {
        $modeloBoleta["Encabezado"]["IdDoc"] = [
            "TipoDTE" => $tipoDTE,
            "Folio" => ++self::$folios[$tipoDTE],
        ];
        $modeloBoleta["Detalle"] = $detalles;
        $modeloBoleta["Referencia"] = [
            'TpoDocRef' => 'SET', // 'SET' solo para set de pruebas, debe ser = $tipoDTE para dte que no son de prueba
            'FolioRef' => self::$folios[$tipoDTE],
            'RazonRef' => 'CASO-'.self::$casos,
        ];
        return $modeloBoleta;
    }

    protected function getToken() {
        // Solicitar seed
        $seed = file_get_contents('https://apicert.sii.cl/recursos/v1/boleta.electronica.semilla');
        $seed = simplexml_load_string($seed);
        $seed = (string) $seed->xpath('//SII:RESP_BODY/SEMILLA')[0];
        //echo "Seed = ".$seed."\n";

        // Obtener Firma
        $Firma = $this->obtenerFirma();

        // Generar xml de semilla firmada
        $seedSigned = $Firma->signXML(
            (new XML("1.0", "UTF-8"))->generate([
                'getToken' => [
                    'item' => [
                        'Semilla' => $seed
                    ]
                ]
            ])->saveXML()
        );
        //echo $seedSigned."\n";

        // Solicitar token con la semilla firmada
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>$seedSigned,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/xml',
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);

        $responseXml = simplexml_load_string($response);

        // Guardar Token con su timestamp en config.json
        $token = (string) $responseXml->xpath('//TOKEN')[0];
        //$token = Autenticacion::getToken($this->obtenerFirma());  //CAMBIAR POR LA DE ARRIBA******
        //echo 'TOKEN='.$token."\n";
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $config_file->token = $token;
        $config_file->token_timestamp = Carbon::now('America/Santiago')->timestamp;;
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    protected function getTokenDte() {
        // Set ambiente certificacion
        Sii::setAmbiente(Sii::CERTIFICACION);
        $token = Autenticacion::getToken($this->obtenerFirma());
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $config_file->token_dte = $token;
        $config_file->token_dte_timestamp = Carbon::now('America/Santiago')->timestamp;;
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    protected function isToken() {
        if(file_exists(base_path('config.json'))) {
            // Obtener config.json
            $config_file = json_decode(file_get_contents(base_path('config.json')));

            // Verificar token
            if($config_file->token === '' || $config_file->token === false || $config_file->token_timestamp === false) {
                $this->getToken();
                //echo "Se generó un nuevo token\n";
            } else {
                $now = Carbon::now('America/Santiago')->timestamp;
                $tokenTimeStamp = $config_file->token_timestamp;
                $diff = $now - $tokenTimeStamp;
                if($diff > $config_file->token_expiration) {
                    $this->getToken();
                    //echo "Se generó un nuevo token\n";
                } else {
                    //echo "El token aún es válido\n";
                }
            }

            // Verificar token_dte
            if($config_file->token_dte === '' || $config_file->token_dte === false || $config_file->token_dte_timestamp === false) {
                $this->getTokenDte();
                //echo "Se generó un nuevo token_dte\n";
            } else {
                $now = Carbon::now('America/Santiago')->timestamp;;
                $tokenDteTimeStamp = $config_file->token_dte_timestamp;
                $diff = $now - $tokenDteTimeStamp;
                if ($diff > $config_file->token_dte_expiration) {
                    $this->getTokenDte();
                    //echo "Se generó un nuevo token_dte\n";
                } else {
                    //echo "El token_dte aún es válido\n";
                }
            }
        } else {
            file_put_contents(base_path('config.json'), json_encode([
                'token' => '',
                'token_timestamp' => '',
                'token_expiration' => 3600,
                'token_dte' => '',
                'token_dte_timestamp' => '',
                'token_dte_expiration' => 3600
            ]), JSON_PRETTY_PRINT);
            $this->getToken();
        }
    }
}

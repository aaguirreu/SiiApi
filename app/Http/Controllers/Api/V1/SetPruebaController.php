<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii\Autenticacion;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\Sii\Folios;
use sasco\LibreDTE\XML;
use SimpleXMLElement;

class SetPruebaController extends Controller
{
    private $timestamp;

    public function index(Request $request) {
        // setear timestamp
        $this->timestamp = Carbon::now();

        // Leer string como json
        $rbody = json_encode($request->json()->all());

        // Transformar a json
        $json = json_decode($rbody);

        // Schema del json
        $schemaJson = file_get_contents(base_path().'\SchemasSwagger\SchemaBoleta.json');

        // Validar json
        //$schema = Schema::import(json_decode($schemaJson));
        //$schema->in($json));

        //$jsonArr = var_dump($json);
        $this->setPrueba($json);
    }

    public function setPrueba($dte) {
        // Renovar token si es necesario
        $this->isToken();

        // Primer folio a usar para envio de set de pruebas

        // Obtiene los folios con la cantidad de folios usados desde la base de datos
        $folios = $this->obtenerFolios();
        // Variable auxiliar para guardar el folio inicial

        // Obtener caratula
        $caratula = $this->obtenerCaratula($dte);

        // Parseo de boletas según modelo libreDTE
        $boletas = $this->parseSetPrueba($dte, $folios);

        // Objetos de Firma y Folios
        $Firma = $this->obtenerFirma();

        // Obtener folios del Caf
        $Folios = $this->obtenerFoliosCaf($folios);

        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $EnvioDTExml = $this->generarEnvioDteXml($boletas, $Firma, $Folios, $caratula);
        echo $EnvioDTExml;

        // Enviar DTE
        $RutEnvia = $Firma->getID(); // RUT autorizado para enviar DTEs
        $RutEmisor = $boletas[0]['Encabezado']['Emisor']['RUTEmisor']; // RUT del emisor del DTE
        //$response = $this->enviar($RutEnvia, $RutEmisor, $EnvioDTExml);
        //echo $response;
    }

    public function estadoDteEnviado(Request $request)
    {
        // setear timestamp
        $this->timestamp = Carbon::now();

        // Renovar token si es necesario
        $this->isToken();

        // Leer string como json
        $rbody = json_encode($request->json()->all());

        // Transformar a json
        $body = json_decode($rbody);

        // Schema del json
        //$schemaJson = file_get_contents(base_path().'\SchemasSwagger\SchemaStatusBE.json');

        // Validar json
        //$schema = Schema::import(json_decode($schemaJson));
        //$schema->in($body);

        // consultar estado dte
        $rut = $body->rut;
        $dv = $body->dv;
        $trackID = $body->trackID;
        $destino = $rut.'-'.$dv.'-'.$trackID;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.envio/'.$destino,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Cookie: TOKEN='.json_decode(file_get_contents(base_path('config.json')))->token,
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        echo $response;
    }

    public function estadoDte(Request $request)
    {
        // setear timestamp
        $this->timestamp = Carbon::now();

        // Renovar token si es necesario
        $this->isToken();

        // Leer string como json
        $rbody = json_encode($request->json()->all());

        // Transformar a json
        $body = json_decode($rbody);

        // Schema del json
        //$schemaJson = file_get_contents(base_path().'\SchemasSwagger\SchemaStatusBE.json');

        // Validar json
        //$schema = Schema::import(json_decode($schemaJson));
        //$schema->in($body);

        // Consulta estado dte
        $rut = $body->rut;
        $dv = $body->dv;
        $tipo = $body->tipo;
        $folio = $body->folio;
        $rut_receptor = $body->rut_receptor;
        $dv_receptor = $body->dv_receptor;
        $monto = $body->monto;
        $fechaEmision = $body->fechaEmision;
        $required = $rut.'-'.$dv.'-'.$tipo.'-'.$folio;
        $opcionales = '?rut_receptor='.$rut_receptor.'&dv_receptor='.$dv_receptor.'&monto='.$monto.'&fechaEmision='.$fechaEmision;
        $url = 'https://apicert.sii.cl/recursos/v1/boleta.electronica/'.$required.'/estado'.$opcionales;
        echo $url."\n";
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Cookie: TOKEN='.json_decode(file_get_contents(base_path('config.json')))->token,
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        echo $response;
    }

    // Subir caf a la base de datos

    /**
     * @throws \Exception
     */
    public function subirCaf(Request $request): JsonResponse
    {
        // setear timestamp
        $this->timestamp = Carbon::now();

        // Leer string como xml
        $rbody = $request->getContent();
        $caf = new simpleXMLElement($rbody);

        // Si el caf no sigue el orden de folios correspondiente, no se sube.
        $folio_caf = $caf->CAF->DA->TD[0];
        $folio = DB::table('caf')->where('folio_id','=', $folio_caf)->latest()->first();
        $folio_final = $folio->folio_final;

        if ($folio_final + 1 != intval($caf->CAF->DA->RNG->D[0])) {
            return response()->json([
                'message' => 'El caf no sigue el orden de folios correspondiente. Folio final: '.$folio_final.', folio caf: '.$caf->CAF->DA->RNG->D[0].'. Deben ser consecutivos.'
            ], 400);
        }

        // Nombre caf tipodte_timestamp.xml
        $filename = $caf->CAF->DA->TD[0].'_'.$this->timestamp.'.xml';
        $filename = str_replace(' ', 'T', $filename);
        $filename = str_replace(':', '-', $filename);

        //Guardar caf en storage
        Storage::disk('cafs')->put($filename, $rbody);

        // Guardar en base de datos
         DB::table('caf')->insert([
             'folio_id' => $folio_caf,
             'folio_inicial' => $caf->CAF->DA->RNG->D[0],
             'folio_final' => $caf->CAF->DA->RNG->H[0],
             'xml_filename' => $filename,
             'created_at' => $this->timestamp,
             'updated_at' => $this->timestamp
        ]);

         // Mensaje de caf guardado
         return response()->json([
             'message' => 'CAF guardado correctamente'
         ], 200);
    }

    private function generarModeloBoleta($modeloBoleta, $detalles, $folio, $tipoDTE, $casos): array
    {
        $modeloBoleta["Encabezado"]["IdDoc"] = [
            "TipoDTE" => $tipoDTE,
            "Folio" => $folio[$tipoDTE],
        ];
        $modeloBoleta["Detalle"] = $detalles;
        $modeloBoleta["Referencia"] = [
            'TpoDocRef' => 'SET', // 'SET' solo para set de pruebas, debe ser = $tipoDTE para dte que no son de prueba
            'FolioRef' => $folio[$tipoDTE],
            'RazonRef' => 'CASO-'.$casos,
        ];
        return $modeloBoleta;
    }


    private function enviar($usuario, $empresa, $dte)
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
            $file = env('DTES_PATH', "")."EnvioBOLETA/".$filename;
        } while (file_exists($file));
        Storage::disk('dtes')->put('envioBOLETA/'.$filename, $dte);
        //file_put_contents($file, $dte);
        $data = [
            'rutSender' => $rutSender,
            'dvSender' => $dvSender,
            'rutCompany' => $rutCompany,
            'dvCompany' => $dvCompany,
            'archivo' => new CURLFILE($file),
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
        // si no se debe verificar el SSL se asigna opción a curl, además si
        // se está en el ambiente de producción y no se verifica SSL se
        // generará una entrada en el log
        // enviar XML al SII
        $response = curl_exec($curl);

        unlink($file);

        // cerrar sesión curl
        curl_close($curl);
        return $response;
    }
    private function getToken() {
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
        $config_file->token_timestamp = Carbon::now()->timestamp;;
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    private function getTokenDte() {
        $token = Autenticacion::getToken($this->obtenerFirma());
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $config_file->token_dte = $token;
        $config_file->token_dte_timestamp = Carbon::now()->timestamp;;
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    private function isToken() {
        if(file_exists(base_path('config.json'))) {
            // Obtener config.json
            $config_file = json_decode(file_get_contents(base_path('config.json')));

            // Verificar token
            if($config_file->token === '' || $config_file->token === false || $config_file->token_timestamp === false) {
                $this->getToken();
                //echo "Se generó un nuevo token\n";
            } else {
                $now = Carbon::now()->timestamp;
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
                $now = Carbon::now()->timestamp;;
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

    private function obtenerFirma() {
        // Firma .p12
        $config = [
            'firma' => [
                'file' => env("CERT_PATH", ""),
                //'data' => '', // contenido del archivo certificado.p12
                'pass' => env("CERT_PASS", "")
            ],
        ];
        return new FirmaElectronica($config['firma']);
    }
    private function parseSetPrueba($dte, $folios) {
        // Contador Casos (número de documentos a enviar)
        // SOLO PARA SET DE PRUEBAS
        $casos = 1;

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
                $modeloBoletaExenta = $this->generarModeloBoleta($modeloBoleta, $detallesExentos, $folios, 41, $casos);
                $boletas[] = $modeloBoletaExenta;
                $folios[41]++;
            }

            if (!empty($detallesAfectos)) {
                $modeloBoletaAfecta = $this->generarModeloBoleta($modeloBoleta, $detallesAfectos, $folios, 39, $casos);
                $boletas[] = $modeloBoletaAfecta;
                $folios[39]++;
            }
            $casos++;
        }
        return $boletas;
    }
    private function obtenerFolios() {
        return [
            39 => DB::table('folio')->where('id',39)->value('cant_folios'), // boleta electrónica, es igual al último folio
            41 => DB::table('folio')->where('id',39)->value('cant_folios'), // boleta afecta o exenta electrónica, es igual al último folio
        ];
        // Solo para set de pruebas
        /*
        return [
            39 => 1, // boleta electrónica, es igual al último folio
            41 => 1, // boleta afecta o exenta electrónica, es igual al último folio
        ];
        */
    }

    private function obtenerCaratula($dte)
    {
        return [
            //'RutEnvia' => '11222333-4', // se obtiene automáticamente de la firma
            'RutReceptor' => $dte->Caratula->RutReceptor,
            'FchResol' => $dte->Caratula->FchResol,
            'NroResol' => $dte->Caratula->NroResol,
        ];
    }

    private function generarEnvioDteXml(array $boletas, FirmaElectronica $Firma, array $Folios, array $caratula)
    {
        $EnvioDTEBE = new EnvioDte();
        foreach ($boletas as $documento) {
            $DTE = new Dte($documento);
            if (!$DTE->timbrar($Folios[$DTE->getTipo()]))
                break;
            if (!$DTE->firmar($Firma))
                break;
            $EnvioDTEBE->agregar($DTE);
        }
        $EnvioDTEBE->setFirma($Firma);
        $EnvioDTEBE->setCaratula($caratula);
        $EnvioDTEBE->generar();
        $EnvioDTExml = new XML();
        if ($EnvioDTEBE->schemaValidate()) {
            echo $EnvioDTExml = $EnvioDTEBE->generar();
        }
        // si hubo errores mostrar
        foreach (Log::readAll() as $error)
            echo $error,"\n";
        return $EnvioDTExml;
    }

    private function obtenerFoliosCaf(array $folios)
    {
        $Folios = [];
        foreach ($folios as $tipo => $cantidad) {
            $row = DB::table('caf')->where('folio_id', '=', $tipo)->latest()->first();
            $Folios[$tipo] = new Folios(Storage::disk('cafs')->get($row->xml_filename));
        }
        return $Folios;
    }
}

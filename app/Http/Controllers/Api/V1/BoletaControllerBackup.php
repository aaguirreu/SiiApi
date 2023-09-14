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
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\Sii\Autenticacion;
use sasco\LibreDTE\Sii\ConsumoFolio;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\Sii\Folios;
use sasco\LibreDTE\XML;
use SimpleXMLElement;

class BoletaControllerBackup extends Controller
{
    private $timestamp;
    private static $retry = 10;
    private static $folios = [];

    public function envio(Request $request): JsonResponse {
        // Leer string como json
        $dte = json_decode(json_encode($request->json()->all()));

        // Schema del json
        //$schemaJson = file_get_contents(base_path().'\SchemasSwagger\SchemaBoleta.json');

        // Validar json
        //$schema = Schema::import(json_decode($schemaJson));
        //$schema->in($json));

        //$jsonArr = var_dump($json);

        // setear timestamp
        $this->timestamp = Carbon::now('America/Santiago');

        // Renovar token si es necesario
        $this->isToken();

        // Obtiene los folios con la cantidad de folios usados desde la base de datos
        $folios_inicial = $this->obtenerFolios();

        // Variable auxiliar para guardar el folio inicial

        // Obtener caratula
        $caratula = $this->obtenerCaratula($dte);

        // Obtener folios del Caf
        $Folios = $this->obtenerFoliosCaf();

        // Parseo de boletas según modelo libreDTE
        $boletas = $this->parseSetPrueba($dte);

        // Objetos de Firma y Folios
        $Firma = $this->obtenerFirma();

        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $EnvioDTExml = $this->generarEnvioDteXml($boletas, $Firma, $Folios, $caratula);
        if(gettype($EnvioDTExml) == 'array') {
            return response()->json([
                'message' => "Error al generar el envio de DTEs",
                'errores' => json_decode(json_encode($EnvioDTExml)),
            ], 400);
        }

        // Enviar DTE e insertar en base de datos de ser exitoso
        $RutEnvia = $Firma->getID(); // RUT autorizado para enviar DTEs
        $RutEmisor = $boletas[0]['Encabezado']['Emisor']['RUTEmisor']; // RUT del emisor del DTE
        $result = $this->enviar($RutEnvia, $RutEmisor, $EnvioDTExml);

        // generar rcof (consumo de folios) y enviar
        $ConsumoFolioxml = $this->generarRCOF($EnvioDTExml);

        // Enviar RCOF e insertar en base de datos de ser exitoso
        $rcofreponse = $this->enviarRcof($ConsumoFolioxml);

        // Actualizar folios en la base de datos
        $this->actualizarFolios($folios_inicial);

        // Guardar dte en storage
        $filename = 'EnvioBOLETA_'.$this->timestamp.'.xml';
        $filename = str_replace(' ', 'T', $filename);
        $filename = str_replace(':', '-', $filename);
        Storage::disk('dtes')->put('envioBOLETA/'.$filename, $dte);

        // Insertar envio dte en la base de datos
        $this->insertarEnvioDte(json_decode($result->getContent())->response->TRACKID, $filename);

        return $result;
    }

    public function estadoDteEnviado(Request $request): JsonResponse
    {
        // setear timestamp
        $this->timestamp = Carbon::now('America/Santiago');

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
        $token = json_decode(file_get_contents(base_path('config.json')))->token_dte;

        // Set ambiente certificacion
        Sii::setAmbiente(Sii::CERTIFICACION);

        // Enviar consulta de estado
        $estado = Sii::request('QueryEstUp', 'getEstUp', [$rut, $dv, $trackID, $token]);

        // si el estado se pudo recuperar se muestra estado y glosa
        if ($estado!==false) {
            print_r([
                'codigo' => (string)$estado->xpath('/SII:RESPUESTA/SII:RESP_HDR/ESTADO')[0],
                'glosa' => (string)$estado->xpath('/SII:RESPUESTA/SII:RESP_HDR/GLOSA')[0],
            ]);
        }

        // mostrar error si hubo
        foreach (Log::readAll() as $error)
            echo $error,"\n";
        return response()->json([
            'message' => "Error al consultar estado de DTE",
            'errores' => json_decode(json_encode(Log::readAll())),
        ], 400);
    }

    public function estadoDte(Request $request): JsonResponse
    {
        // setear timestamp
        $this->timestamp = Carbon::now('America/Santiago');

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
        //echo $url."\n";
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
        return response()->json([
            'response' => json_decode($response),
        ], 200);
    }

    public function subirCaf(Request $request):JsonResponse
    {
        return $this->uploadCaf($request);
    }

    public function forzarSubirCaf(Request $request): JsonResponse
    {
        return $this->uploadCaf($request, true);
    }

    public function uploadCaf($request, ?bool $force = false)
    {
        // setear timestamp
        $this->timestamp = Carbon::now('America/Santiago');

        // Leer string como xml
        $rbody = $request->getContent();
        $caf = new simpleXMLElement($rbody);

        // Si el caf no sigue el orden de folios correspondiente, no se sube.
        $folio_caf = $caf->CAF->DA->TD[0];
        if (!$force) {
            $folio = DB::table('caf')->where('folio_id','=', $folio_caf)->latest()->first();
            $folio_final = $folio->folio_final;
        } else {
            $folio_final = (int)$caf->CAF->DA->RNG->D[0];
            $folio_final--;
        }
        if ($folio_final + 1 != intval($caf->CAF->DA->RNG->D[0])) {
            return response()->json([
                'message' => 'El caf no sigue el orden de folios correspondiente. Folio final: '.$folio_final.', folio caf enviado: '.$caf->CAF->DA->RNG->D[0].'. Deben ser consecutivos.'
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

    public function actualizarFolios($folios_inicial) {
        if($folios_inicial[39] <= self::$folios[39])
            DB::table('folio')->where('id', 39)->update(['cant_folios' => self::$folios[39], 'updated_at' => $this->timestamp]);
        if($folios_inicial[41] <= self::$folios[41])
            DB::table('folio')->where('id', 41)->update(['cant_folios' => self::$folios[41], 'updated_at' => $this->timestamp]);
    }

    private function generarRCOF($boletas){

        // cargar XML boletas y notas
        $EnvioBOLETA = new EnvioDte();
        $EnvioBOLETA->loadXML($boletas);

        // crear objeto para consumo de folios
        $ConsumoFolio = new ConsumoFolio();
        $ConsumoFolio->setFirma($this->obtenerFirma());
        $ConsumoFolio->setDocumentos([39, 41]); // [39, 61] si es sólo afecto, [41, 61] si es sólo exento

        // agregar detalle de boletas
        foreach ($EnvioBOLETA->getDocumentos() as $Dte) {
            $ConsumoFolio->agregar($Dte->getResumen());
        }

        // crear carátula para el envío (se hace después de agregar los detalles ya que
        // así se obtiene automáticamente la fecha inicial y final de los documentos)
        $CaratulaEnvioBOLETA = $EnvioBOLETA->getCaratula();
        $ConsumoFolio->setCaratula([
            'RutEmisor' => $CaratulaEnvioBOLETA['RutEmisor'],
            'FchResol' => $CaratulaEnvioBOLETA['FchResol'],
            'NroResol' => $CaratulaEnvioBOLETA['NroResol'],
        ]);

        // generar y validar schema
        $ConsumoFolio->generar();
        if (!$ConsumoFolio->schemaValidate()) {
            // si hubo errores mostrar
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return $errores;
        }
        return $ConsumoFolio;
    }

    private function enviarRcof(ConsumoFolio $ConsumoFolio) {
        // Set ambiente certificacion
        Sii::setAmbiente(Sii::CERTIFICACION);

        // Enviar rcof
        $response = $ConsumoFolio->enviar();

        if($response != false){
            // Guardar xml en storage
            $filename = 'RCOF_'.$this->timestamp.'.xml';
            $filename = str_replace(' ', 'T', $filename);
            $filename = str_replace(':', '-', $filename);
            //$file = env('DTES_PATH', "")."EnvioBOLETA/".$filename;
            Storage::disk('dtes')->put('envioRcof/'.$filename, $ConsumoFolio->generar());
        }

        return $response;
    }

    // Se debe enviar el xml del EnvioBoleta que se desea realizar el resumen rcof.
    public function enviarRcofOnly(Request $request): JsonResponse
    {
        // setear timestamp
        $this->timestamp = Carbon::now('America/Santiago');

        // Renovar token si es necesario
        $this->isToken();

        // Obtener resumen de consumo de folios
        $EnvioBoletaxml = $request->getContent();
        $ConsumoFolio = $this->generarRCOF($EnvioBoletaxml);

        // Enviar rcof
        $response = $ConsumoFolio->enviar(5);
        if($response != false) {
            return response()->json([
                'message' => 'RCOF enviado correctamente',
                'trackid' => $response
            ], 200);
        }
        return response()->json([
            'message' => 'Error al enviar RCOF',
            'response' => $response
        ], 400);
    }

    private function generarModeloBoleta($modeloBoleta, $detalles, $tipoDTE, $casos): array
    {
        $modeloBoleta["Encabezado"]["IdDoc"] = [
            "TipoDTE" => $tipoDTE,
            "Folio" => ++self::$folios[$tipoDTE],
        ];
        $modeloBoleta["Detalle"] = $detalles;
        $modeloBoleta["Referencia"] = [
            'TpoDocRef' => 'SET', // 'SET' solo para set de pruebas, debe ser = $tipoDTE para dte que no son de prueba
            'FolioRef' => self::$folios[$tipoDTE],
            'RazonRef' => 'CASO-'.$casos,
        ];
        return $modeloBoleta;
    }

    private function enviar($usuario, $empresa, $dte)
    {
        $token = json_decode(file_get_contents(base_path('config.json')))->token_dte;

        // Set ambiente certificacion
        Sii::setAmbiente(Sii::CERTIFICACION);

        // Enviar dte
        $result = Sii::enviar($usuario, $empresa, $dte, $token);

        // si hubo algún error al enviar al servidor mostrar
        if ($result===false) {
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return response()->json([
                'message' => 'Error al enviar DTE',
                'response' => json_decode(json_encode($errores))
            ], 400);
        }

        // Mostrar resultado del envío
        if ($result->STATUS!='0') {
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return response()->json([
                'message' => 'Error en el envío del DTE',
                'response' => json_decode(json_encode($errores))
            ], 400);
        }
        return response()->json([
            'message' => 'DTE enviado correctamente',
            'response' => $result
        ], 200);
    }

    private function insertarEnvioDte($trackid, $filename) {
        // Insertar envio dte en la base de datos
        DB::table('envio_dte')->insert([
            'trackid' => $trackid,
            'xml_filename' => $filename,
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
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
        $config_file->token_timestamp = Carbon::now('America/Santiago')->timestamp;;
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    private function getTokenDte() {
        // Set ambiente certificacion
        Sii::setAmbiente(Sii::CERTIFICACION);
        $token = Autenticacion::getToken($this->obtenerFirma());
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $config_file->token_dte = $token;
        $config_file->token_dte_timestamp = Carbon::now('America/Santiago')->timestamp;;
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
    private function parseSetPrueba($dte) {
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
                $modeloBoletaExenta = $this->generarModeloBoleta($modeloBoleta, $detallesExentos, 41, $casos);
                $boletas[] = $modeloBoletaExenta;
            }

            if (!empty($detallesAfectos)) {
                $modeloBoletaAfecta = $this->generarModeloBoleta($modeloBoleta, $detallesAfectos, 39, $casos);
                $boletas[] = $modeloBoletaAfecta;
            }
            $casos++;
        }
        return $boletas;
    }
    private function obtenerFolios() {
        self::$folios = [
            39 => DB::table('folio')->where('id',39)->value('cant_folios'), // boleta electrónica, es igual al último folio
            41 => DB::table('folio')->where('id',41)->value('cant_folios'), // boleta afecta o exenta electrónica, es igual al último folio
        ];

        // valor que toma folio_inicial
        return  [
            39 => self::$folios[39] + 1, // se le suma uno al último folio
            41 => self::$folios[41] + 1, // se le suma uno al último folio
        ];

        // Solo para set de pruebas
        /*
        return [
            39 => 0, // boleta electrónica, es igual al último folio
            41 => 0, // boleta afecta o exenta electrónica, es igual al último folio
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
            $EnvioDTExml = $EnvioDTEBE->generar();
        } else {
            // si hubo errores mostrar
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return $errores;
        }
        return $EnvioDTExml;
    }

    private function obtenerFoliosCaf()
    {
        $Folios = [];
        foreach (self::$folios as $tipo => $cantidad) {
            $row = DB::table('caf')->where('folio_id', '=', $tipo)->latest()->first();
            try {
                $Folios[$tipo] = new Folios(Storage::disk('cafs')->get($row->xml_filename));
            } catch (\Exception $e) {
                echo $e->getMessage(). "No existe el caf para el folio " . $tipo . "\n";
            }
        }
        return $Folios;
    }
}

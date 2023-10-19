<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
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

class DteController extends Controller
{
    protected $timestamp;
    protected static $retry = 10;
    protected static $folios = [];
    protected static $folios_inicial = [];
    protected static $tipos_dte = [];

    protected function actualizarFolios()
    {
        foreach (self::$tipos_dte as $tipo_dte) {
            if(self::$folios_inicial[$tipo_dte] <= self::$folios[$tipo_dte])
                DB::table('folio')->where('id', $tipo_dte)->update(['cant_folios' => self::$folios[$tipo_dte], 'updated_at' => $this->timestamp]);
        }
    }

    protected function uploadCaf($request, ?bool $force = false)
    {
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

    protected function generarRCOF($boletas)
    {
        // cargar XML boletas y notas
        $EnvioBOLETA = new EnvioDte();
        // podría ser un arrai de EnvioBoleta
        $EnvioBOLETA->loadXML($boletas);

        // crear objeto para consumo de folios
        $ConsumoFolio = new ConsumoFolio();
        $ConsumoFolio->setFirma($this->obtenerFirma());
        $ConsumoFolio->setDocumentos([39, 41]); // [39, 61] si es sólo afecto, [41, 61] si es sólo exento

        // agregar detalle de boletas
        // Se puede recorrer un array de EnvioDTE
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

    protected function enviarRcof(ConsumoFolio $ConsumoFolio, $dte_filename) {
        // Set ambiente certificacion
        Sii::setAmbiente(Sii::PRODUCCION);

        // Enviar rcof
        $response = $ConsumoFolio->enviar(self::$retry);

        if($response != false){
            // Guardar xml en storage
            $filename = 'RCOF_'.$this->timestamp.'.xml';
            $filename = str_replace(' ', 'T', $filename);
            $filename = str_replace(':', '-', $filename);
            //$file = env('DTES_PATH', "")."EnvioBOLETA/".$filename;
            Storage::disk('dtes')->put('EnvioRcof/'.$filename, $ConsumoFolio->generar());

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

    protected function generarModeloBoleta($modeloBoleta, $detalles, $tipoDTE): array
    {
        $modeloBoleta["Encabezado"]["IdDoc"] = [
            "TipoDTE" => $tipoDTE,
            "Folio" => ++self::$folios[$tipoDTE],
        ];
        $modeloBoleta["Detalle"] = $detalles;
        /*$modeloBoleta["Referencia"] = [
            'TpoDocRef' => $tipoDTE,
            'FolioRef' => self::$folios[$tipoDTE],
            'RazonRef' => 'LibreDTE_T'.$tipoDTE.'F'.self::$folios[$tipoDTE],
        ];*/
        return $modeloBoleta;
    }

    protected function guardarEnvioDte($response, $filename) {
        // Insertar envio dte en la base de datos
        $enviodte_id = DB::table('envio_dte')->insertGetId([
            'trackid' => $response->trackid ?? $response->TRACKID,
            'xml_filename' => $filename,
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);

        // Insertar en tabla envíodte_caf (many to many) en la base de datos
        foreach (self::$folios as $key=>$value) {
            if (self::$folios_inicial[$key] <= $value) {
                $caf_id = DB::table('caf')->where('folio_id', '=', $key)->latest()->first()->id;
                DB::table('enviodte_caf')->insert([
                    'enviodte_id' => $enviodte_id,
                    'caf_id' => $caf_id,
                    'created_at' => $this->timestamp,
                    'updated_at' => $this->timestamp
                ]);
            }
        }
    }

    protected function getToken() {
        // Solicitar seed
        $seed = file_get_contents('https://api.sii.cl/recursos/v1/boleta.electronica.semilla');
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
            CURLOPT_URL => 'https://api.sii.cl/recursos/v1/boleta.electronica.token',
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

    protected function getTokenDte()
    {
        // Set ambiente producción
        Sii::setAmbiente(Sii::PRODUCCION);
        $token = Autenticacion::getToken($this->obtenerFirma());
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $config_file->token_dte = $token;
        $config_file->token_dte_timestamp = Carbon::now('America/Santiago')->timestamp;;
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    protected function isToken()
    {
        if(file_exists(base_path('config.json'))) {
            // Obtener config.json
            $config_file = json_decode(file_get_contents(base_path('config.json')));

            // Verificar token
            if($config_file->token === '' || $config_file->token === false || $config_file->token_timestamp === false) {
                $this->getToken();
            } else {
                $now = Carbon::now('America/Santiago')->timestamp;
                $tokenTimeStamp = $config_file->token_timestamp;
                $diff = $now - $tokenTimeStamp;
                if($diff > $config_file->token_expiration) {
                    $this->getToken();
                }
            }

            // Verificar token_dte
            if($config_file->token_dte === '' || $config_file->token_dte === false || $config_file->token_dte_timestamp === false) {
                $this->getTokenDte();
            } else {
                $now = Carbon::now('America/Santiago')->timestamp;;
                $tokenDteTimeStamp = $config_file->token_dte_timestamp;
                $diff = $now - $tokenDteTimeStamp;
                if ($diff > $config_file->token_dte_expiration) {
                    $this->getTokenDte();
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

    protected function obtenerFirma(): FirmaElectronica
    {
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

    protected function parseDte($dte): array
    {
        $boletas = [];
        foreach ($dte->Boletas as $boleta) {
            // Modelo boleta
            $modeloBoleta = [
                "Encabezado" => [
                    "IdDoc" => [],
                    "Emisor" => [
                        'RUTEmisor' => $boleta->Encabezado->Emisor->RUTEmisor ?? false,
                        'RznSoc' => $boleta->Encabezado->Emisor->RznSoc ?? false,
                        'GiroEmis' => $boleta->Encabezado->Emisor->GiroEmis ?? false,
                        'DirOrigen' => $boleta->Encabezado->Emisor->DirOrigen ?? false,
                        'CmnaOrigen' => $boleta->Encabezado->Emisor->CmnaOrigen ?? false,
                    ],
                    "Receptor" => [
                        'RUTRecep' => $boleta->Encabezado->Receptor->RUTRecep ?? '000-0',
                        'RznSocRecep' => $boleta->Encabezado->Receptor->RznSocRecep ?? false,
                        'GiroRecep' => $boleta->Encabezado->Receptor->GiroRecep ?? false,
                        'DirRecep' => $boleta->Encabezado->Receptor->DirRecep ?? false,
                        'CmnaRecep' => $boleta->Encabezado->Receptor->CmnaRecep ?? false,
                        'CiudadRecep' => $boleta->Encabezado->Receptor->CiudadRecep ?? false,
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

    protected function obtenerFolios(): array
    {
        $folios = [];
        foreach (self::$tipos_dte as $key) {
            self::$folios[$key] = DB::table('folio')->where('id',$key)->value('cant_folios');
            $folios[$key] = self::$folios[$key] + 1;
        }
        return $folios;
    }

    protected function obtenerCaratula($dte): array
    {
        return [
            //'RutEnvia' => '11222333-4', // se obtiene automáticamente de la firma
            'RutReceptor' => $dte->Caratula->RutReceptor,
            'FchResol' => $dte->Caratula->FchResol,
            'NroResol' => $dte->Caratula->NroResol,
        ];
    }

    protected function generarEnvioDteXml(array $boletas, FirmaElectronica $Firma, array $Folios, array $caratula)
    {
        $EnvioDTE = new EnvioDte();
        foreach ($boletas as $documento) {
            $DTE = new Dte($documento);
            if (!$DTE->timbrar($Folios[$DTE->getTipo()]))
                break;
            if (!$DTE->firmar($Firma))
                break;
            $EnvioDTE->agregar($DTE);
        }
        $EnvioDTE->setFirma($Firma);
        $EnvioDTE->setCaratula($caratula);
        $EnvioDTE->generar();
        $EnvioDTExml = new XML();
        if ($EnvioDTE->schemaValidate()) {
            $EnvioDTExml = $EnvioDTE->generar();
        } else {
            //return $EnvioDTExml = $EnvioDTE->generar();
            // si hubo errores mostrar
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return $errores;
        }
        return $EnvioDTExml;
    }

    protected function obtenerFoliosCaf(): array
    {
        $Folios = [];
        foreach (self::$folios as $tipo => $cantidad) {
            $xml_filename = DB::table('caf')->where('folio_id', '=', $tipo)->latest()->first()->xml_filename;
            try {
                $Folios[$tipo] = new Folios(Storage::disk('cafs')->get($xml_filename));
            } catch (\Exception $e) {
                echo $e->getMessage(). "No existe el caf para el folio " . $tipo . "\n";
            }
        }
        return $Folios;
    }
}

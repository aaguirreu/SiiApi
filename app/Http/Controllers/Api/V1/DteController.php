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
        foreach (self::$folios as $key => $value) {
            if(self::$folios_inicial[$key] <= self::$folios[$key])
                DB::table('secuencia_folio')->where('id', $key)->update(['cant_folios' => self::$folios[$key], 'updated_at' => $this->timestamp]);
        }
    }

    protected function uploadCaf($request, ?bool $force = false)
    {
        // Leer string como xml
        $rbody = $request->getContent();
        $caf = new simpleXMLElement($rbody);

        // Si el caf no sigue el orden de folios correspondiente, no se sube.
        $folio_caf = $caf->CAF->DA->TD[0];

        /* Consulta si existe el folio en la base de datos
         * Si existe, se obtiene el último folio final y se compara con el folio inicial del caf
         * Si no existe, se guarda el caf
         */
        if(DB::table('caf')->where('folio_id','=', $folio_caf)->exists()){
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
        } else if(DB::table('secuencia_folio')->where('id', '=', $folio_caf)->doesntExist()) {
            $cant_folios = intval($caf->CAF->DA->RNG->D[0]);
            DB::table('secuencia_folio')->insert([
                'id' => $folio_caf,
                'cant_folios' => --$cant_folios,
                'created_at' => $this->timestamp,
                'updated_at' => $this->timestamp
            ]);
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

    protected function generarModeloDocumento($modeloDocumento, $detalles, $tipoDTE): array
    {
        $modeloDocumento["Encabezado"]["IdDoc"]["TipoDTE"] = $modeloDocumento["Encabezado"]["IdDoc"]["TipoDTE"] ?? $tipoDTE;
        $modeloDocumento["Encabezado"]["IdDoc"]["Folio"] = $modeloDocumento["Encabezado"]["IdDoc"]["Folio"] ?? ++self::$folios[$tipoDTE];
        $modeloDocumento["Detalle"] = $detalles;

        return $modeloDocumento;
    }

    protected function guardarEnvioDte($response) {
        return DB::table('envio_dte')->insertGetId([
            'trackid' => $response->trackid ?? $response->TRACKID,
            'status' => 'Enviado',
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
    }

    protected function updateEnvioDte($estado, $id) {
        if($estado) {
            // Obtener row con id
            $enviodte = DB::table('envio_dte')->where('id', '=', $id)->first();
            // Insertar envio dte en la base de datos
            $enviodte->update([
                'status' => 'Procesado',
                'created_at' => $this->timestamp,
                'updated_at' => $this->timestamp
            ]);
        } else {
            // borrar row con id en cascada
            DB::table('envio_dte')->where('id', '=', $id)->delete();
        }

        return $id;
    }

    protected function guardarXmlDB($envioResponse, $filename, $caratula, $dte) {
        try {
            DB::beginTransaction(); // <= Starting the transaction

            $envioDteId = $this->guardarEnvioDte($envioResponse);
            $emisorID = $this->getEmpresa($caratula['RutEmisor'], $dte);
            $caratulaId = $this->getCaratula($caratula);
            $dteId = $this->guardarDte($filename, $envioDteId, $caratulaId);
            $documentoId = $this->guardarDocumento($dteId, $envioDteId, $caratulaId);

            DB::commit(); // <= Commit the changes
        } catch (\Exception $e) {
            report($e);

            DB::rollBack(); // <= Rollback in case of an exception
            return json_decode(json_encode($e, true), JSON_PRETTY_PRINT);
        }
    }

    protected function guardarDte($filename, $envioDteId, $caratulaId) {
        return DB::table('dte')->insertGetId([
            'envio_id' => $envioDteId,
            'carautula_id' => $caratulaId,
            'resumen_id' => 0,
            'xml_filename' => $filename,
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
    }

    protected function getEmpresa($rut, $dte) {
        $emisor = DB::table('empresa')->where('rut', '=', $rut)->latest()->first();
        if($emisor) return $emisor->id;
        else {
            return $this->guardarEmpresa($rut, $dte);
        }
    }

    protected function guardarEmpresa($rut, $empresa) {
        return DB::table('empresa')->insertGetId([
            'rut' => $rut,
            'fecha_resolucion' => $empresa['FchResol'] ?? null,
            'razon_social' => $empresa['RznSoc'] ?? null,
            'giro' => $empresa['GiroEmis'] ?? null,
            'acteco' => $empresa['Acteco'] ?? null,
            'direccion' => $empresa['DirOrigen'] ?? null,
            'comuna' => $empresa['CmnaOrigen'] ?? null,
            'ciudad' => $empresa['CiudadOrigen'] ?? null,
            'codigo_vendedor' => $empresa['CodigoVendedor'] ?? null,
            'correo' => $empresa['CorreoEmisor'] ?? null,
            'telefono' => $empresa['Telefono'] ?? null,
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
    }

    protected function getCaratula($caratula){
        // Obtener id emisor
        $idEmisor = DB::table('empresa')->where('rut', '=', $caratula['RutEmisor'])->latest()->first()->id;

        // Verificar si existe caratula
        $existeCaratula = DB::table('caratula')
            ->where('emisor_id', '=', $idEmisor)
            ->where('rut_envia', '=', $caratula['RutEnvia'])
            ->where('rut_receptor', '=', $caratula['RutReceptor'])
            ->get()->first();

        if(!$existeCaratula){
            return $this->guardarCaratula($caratula, $idEmisor);
        } else return $existeCaratula->id;
    }

    protected function guardarCaratula($caratula, $idEmisor) {
        return DB::table('caratula')->insertGetId([
            'emisor_id' => $idEmisor,
            'rut_envia' => $caratula['RutEnvia'],
            'rut_receptor' => $caratula['RutReceptor'],
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
    }

    protected function guardarDocumento() {
        return DB::table('documento')->insert([
            'caf_id' => $cafId,
            'dte_id' => $dteId,
            'numero_folio' => $numeroFolio,
            'tipo_transaccion' => $tipoTransaccion,
            'receptor_id' => $receptorId,
            'monto_total' => $montoTotal,
            'folio_referencia' => $folioReferencia,
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
    }

    protected function guardarDetalle() {

    }

    protected function getTokenBE() {
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
        $tokenBE = (string) $responseXml->xpath('//TOKEN')[0];
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $config_file->tokenBE = $tokenBE;
        $config_file->tokenBE_timestamp = Carbon::now('America/Santiago')->timestamp;;
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    protected function getToken()
    {
        // Set ambiente producción
        Sii::setAmbiente(Sii::PRODUCCION);
        $token = Autenticacion::getToken($this->obtenerFirma());
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $config_file->token = $token;
        $config_file->token_timestamp = Carbon::now('America/Santiago')->timestamp;
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    protected function isToken()
    {
        if(file_exists(base_path('config.json'))) {
            // Obtener config.json
            $config_file = json_decode(file_get_contents(base_path('config.json')));

            // Verificar tokenBE
            if($config_file->tokenBE === '' || $config_file->tokenBE === false || $config_file->tokenBE_timestamp === false) {
                $this->getTokenBE();
            } else {
                $now = Carbon::now('America/Santiago')->timestamp;
                $tokenBETimeStamp = $config_file->tokenBE_timestamp;
                $diff = $now - $tokenBETimeStamp;
                if($diff > $config_file->tokenBE_expiration) {
                    $this->getTokenBE();
                }
            }

            // Verificar token
            if($config_file->token === '' || $config_file->token === false || $config_file->token_timestamp === false) {
                $this->getToken();
            } else {
                $now = Carbon::now('America/Santiago')->timestamp;
                $tokenDteTimeStamp = $config_file->token_timestamp;
                $diff = $now - $tokenDteTimeStamp;
                if ($diff > $config_file->token_expiration) {
                    $this->getToken();
                }
            }
        } else {
            file_put_contents(base_path('config.json'), json_encode([
                'token' => '',
                'token_timestamp' => '',
                'token_expiration' => 3600,
                'tokenBE' => '',
                'tokenBE_timestamp' => '',
                'tokenBE_expiration' => 3600
            ]), JSON_PRETTY_PRINT);
            $this->getToken();
            $this->getTokenBE();
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
        $documentos = [];
        foreach ($dte->Documentos as $documento) {
            // Modelo documento
            $modeloDocumento = [
                "Encabezado" => [
                    "IdDoc" => [],
                    "Emisor" => [
                        'RUTEmisor' => $documento->Encabezado->Emisor->RUTEmisor ?? false,
                        'RznSoc' => $documento->Encabezado->Emisor->RznSoc ?? false,
                        'GiroEmis' => $documento->Encabezado->Emisor->GiroEmis ?? false,
                        'DirOrigen' => $documento->Encabezado->Emisor->DirOrigen ?? false,
                        'CmnaOrigen' => $documento->Encabezado->Emisor->CmnaOrigen ?? false,
                    ],
                    "Receptor" => [
                        'RUTRecep' => $documento->Encabezado->Receptor->RUTRecep ?? '000-0',
                        'RznSocRecep' => $documento->Encabezado->Receptor->RznSocRecep ?? false,
                        'GiroRecep' => $documento->Encabezado->Receptor->GiroRecep ?? false,
                        'DirRecep' => $documento->Encabezado->Receptor->DirRecep ?? false,
                        'CmnaRecep' => $documento->Encabezado->Receptor->CmnaRecep ?? false,
                        'CiudadRecep' => $documento->Encabezado->Receptor->CiudadRecep ?? false,
                    ],
                ],
                "Detalle" => [],
                "Referencia" => [],
            ];

            $detallesExentos = [];
            $detallesAfectos = [];

            foreach ($documento->Detalle as $detalle) {
                if (array_key_exists("IndExe", json_decode(json_encode($detalle), true))) {
                    $detallesExentos[] = json_decode(json_encode($detalle), true);
                } else {
                    $detallesAfectos[] = json_decode(json_encode($detalle), true);
                }
            }

            if (!empty($detallesExentos)) {
                $modeloDocumentoExenta = $this->generarModeloDocumento($modeloDocumento, $detallesExentos, 41);
                $documentos[] = $modeloDocumentoExenta;
            }

            if (!empty($detallesAfectos)) {
                $modeloDocumentoAfecta = $this->generarModeloDocumento($modeloDocumento, $detallesAfectos, 39);
                $documentos[] = $modeloDocumentoAfecta;
            }
        }

        // Compara si el número de folios restante en el caf es mayor o igual al número de documentos a enviar
        foreach (self::$tipos_dte as $key) {
            $folios_restantes = DB::table('caf')->where('folio_id', '=', $key)->latest()->first()->folio_final - DB::table('secuencia_folio')->where('id', '=', $key)->latest()->first()->cant_folios;
            $folios_documentos = self::$folios[$key] - self::$folios_inicial[$key] + 1;
            if ($folios_documentos > $folios_restantes) {
                $response[] = [
                    'error' => 'No hay folios suficientes para generar los documentos',
                    'tipo_folio' => $key,
                    'folios_restantes' => $folios_restantes,
                    'folios_documentos' => $folios_documentos,
                ];
            }
        }

        return $response ?? $documentos;
    }

    protected function obtenerCaratula($dte, $documentos, $firma): array {
        return [
            'RutEmisor' => $dte->Caratula->RutEmisor ?? $documentos[0]['Encabezado']['Emisor']['RUTEmisor'], // se obtiene automáticamente
            'RutEnvia' => $firma->getID(),
            'RutReceptor' => $dte->Caratula->RutReceptor ?? $documentos[0]['Encabezado']['Receptor']['RUTRecep'], // se obtiene automáticamente
            'FchResol' => $dte->Caratula->FchResol,
            'NroResol' => $dte->Caratula->NroResol,
        ];
    }

    protected function generarEnvioDteXml(array $documentos, FirmaElectronica $Firma, array $folios, array $caratula)
    {
        $EnvioDTE = new EnvioDte();
        foreach ($documentos as $documento) {
            $DTE = new Dte($documento);
            //if (!$DTE->timbrar($folios[intval($DTE->getTipo())])) // Probar así para que se vaya el error
            if (!$DTE->timbrar($folios[$DTE->getTipo()]))
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
            return $EnvioDTExml;
        } else {
            //return $EnvioDTExml = $EnvioDTE->generar();
            // si hubo errores mostrar
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return $errores;
        }
    }

    protected function obtenerFolios($dte) {
        $folios = [];
        foreach ($dte->Documentos as $documento) {
            $tipoDte = $documento->Encabezado->IdDoc->TipoDTE;
            if (!in_array($tipoDte, self::$tipos_dte))
                $error['error'][] = "El TipoDTE no es válido. Debe ser 33, 34, 56 y/o 61. Encontrado: $tipoDte";
            self::$folios[$tipoDte] = DB::table('secuencia_folio')->where('id',$tipoDte)->value('cant_folios');
            $folios[$tipoDte] = self::$folios[$tipoDte] + 1;
        }
        return $error ?? $folios;
    }

    protected function obtenerFoliosCaf() {
        $folios = [];
        foreach (self::$folios as $tipo => $cantidad) {
            $caf = DB::table('caf')->where('folio_id', '=', $tipo)->latest()->first();
            if($caf) {
                try {
                    $folios[$tipo] = new Folios(Storage::disk('cafs')->get($caf->xml_filename));
                } catch (\Exception $e) {
                    $error['error'][] = "$e\nNo existe el caf para el folio $tipo en storage";
                }
            } else {
                $error['error'][] = "No existe el caf para el folio $tipo en la base de datos";
            }

        }
        return $error ?? $folios;
    }
}

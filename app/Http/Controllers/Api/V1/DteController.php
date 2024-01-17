<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\Sii\Autenticacion;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\Sii\Folios;
use sasco\LibreDTE\XML;
use SimpleXMLElement;

class DteController extends Controller
{
    protected string $timestamp;
    protected static int $retry = 10;
    protected static array $folios = [];
    protected static array $folios_inicial = [];
    protected static array $tipos_dte = [];
    protected static string $url = '';
    protected static int $ambiente = 0; // 1 Producción, 0 Certificación
    protected static string $token = '';

    protected function actualizarFolios(): void
    {
        foreach (self::$folios as $key => $value) {
            self::$ambiente == 0 ? $tipo = -$key : $tipo = $key;
            if (self::$folios_inicial[$key] <= self::$folios[$key])
                DB::table('secuencia_folio')->where('id', $tipo)->update(['cant_folios' => self::$folios[$key], 'updated_at' => $this->timestamp]);
        }
    }

    /**
     * @throws Exception
     * ARREGLAR: el mismo caf se puede almacenar más de una vez.
     */
    protected function uploadCaf($request, ?bool $force = false)//: JsonResponse
    {
        // Leer string como xml
        $rbody = $request->getContent();
        $caf = new simpleXMLElement($rbody);

        // Si el caf no sigue el orden de folios correspondiente, no se sube.
        $folio_caf = $caf->CAF->DA->TD[0];
        // Si el ambiente es de certificación agregar un 0 al id
        if(self::$ambiente == 0)
            $folio_caf = -$folio_caf;

        /* Consulta si existe el folio en la base de datos
         * Si existe, se obtiene el último folio final y se compara con el folio inicial del caf
         * Si no existe, se guarda el caf
         */
        if (DB::table('caf')->where('folio_id', '=', $folio_caf)->exists()) {
            if (!$force) {
                $folio = DB::table('caf')->where('folio_id', '=', $folio_caf)->latest()->first();
                $folio_final = $folio->folio_final;
            } else {
                $folio_final = (int)$caf->CAF->DA->RNG->D[0];
                $folio_final--;
            }
            if ($folio_final + 1 != intval($caf->CAF->DA->RNG->D[0])) {
                return response()->json([
                    'message' => 'El caf no sigue el orden de folios correspondiente. Folio final: ' . $folio_final . ', folio caf enviado: ' . $caf->CAF->DA->RNG->D[0] . '. Deben ser consecutivos.'
                ], 400);
            }
        } else if (DB::table('secuencia_folio')->where('id', '=', $folio_caf)->doesntExist()) {
            $cant_folios = intval($caf->CAF->DA->RNG->D[0]);
            DB::table('secuencia_folio')->insert([
                'id' => $folio_caf,
                'cant_folios' => --$cant_folios,
                'created_at' => $this->timestamp,
                'updated_at' => $this->timestamp
            ]);
        }

        // Nombre caf tipodte_timestamp.xml
        $filename = $caf->CAF->DA->TD[0] . '_' . $this->timestamp . '.xml';
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
        ]);
    }

    protected function guardarEnvioDte($response): int
    {
        return DB::table('envio_dte')->insertGetId([
            'trackid' => $response->trackid ?? $response->TRACKID,
            'estado' => 'Enviado',
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
    }

    protected function updateEnvioDte($estado, $id)
    {
        if ($estado) {
            // Obtener row con id
            $enviodte = DB::table('envio_dte')->where('id', '=', $id)->first();
            // Insertar envio dte en la base de datos
            $enviodte->update([
                'estado' => 'Procesado',
                'created_at' => $this->timestamp,
                'updated_at' => $this->timestamp
            ]);
        } else {
            // borrar row con id en cascada
            DB::table('envio_dte')->where('id', '=', $id)->delete();
        }

        return $id;
    }

    protected function guardarXmlDB($envioDteId, $filename, $caratula, $dteXml): array|int
    {
        try {
            DB::beginTransaction(); // <= Starting the transaction
            $xml = new SimpleXMLElement($dteXml);
            $emisorID = $this->getEmpresa($caratula['RutEmisor'], $xml->children()->SetDTE->DTE->Documento[0]->Encabezado->Emisor);
            $caratulaId = $this->getCaratula($caratula, $emisorID);
            $dteId = $this->guardarDte($filename, $envioDteId, $caratulaId);
            foreach ($xml->children()->SetDTE->DTE->Documento as $documento) {
                // Si el ambiente es de certificación transformar tipo dte a negativo.
                self::$ambiente == 0 ? $tipo_dte = -$documento->Encabezado->IdDoc->TipoDTE : $tipo_dte = $documento->Encabezado->IdDoc->TipoDTE;
                $cafId = DB::table('caf')->where('folio_id', '=', $tipo_dte)->latest()->first()->id;
                $receptorId = $this->getEmpresa($documento->Encabezado->Receptor->RUTRecep, $documento);
                $documentoId = $this->guardarDocumento($dteId, $cafId, $receptorId, $documento);
                foreach ($documento->Detalle as $detalle) {
                    $this->guardarDetalle($detalle, $documentoId);
                }
            }
            DB::commit(); // <= Commit the changes
            return $dteId;
        } catch (Exception $e) {
            report($e);
            DB::rollBack(); // <= Rollback in case of an exception
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function guardarDte($filename, $envioDteId, $caratulaId): int
    {
        return DB::table('dte')->insertGetId([
            'envio_id' => $envioDteId,
            'caratula_id' => $caratulaId,
            'resumen_id' => null,
            'estado' => null, // ACEPTADO / RECHAZADO
            'xml_filename' => $filename,
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
    }

    protected function getEmpresa($rut, $empresa): int
    {
        $emisor = DB::table('empresa')->where('rut', '=', $rut)->latest()->first();
        if ($emisor) return $emisor->id;
        else {
            return $this->guardarEmpresa($rut, $empresa);
        }
    }

    protected function guardarEmpresa($rut, $empresa): int
    {
        return DB::table('empresa')->insertGetId([
            'rut' => $rut,
            'fecha_resolucion' => $empresa->FchResol ?? null,
            'razon_social' => $empresa->RznSoc ?? null,
            'giro' => $empresa->GiroEmis ?? null,
            'acteco' => $empresa->Acteco ?? null,
            'direccion' => $empresa->DirOrigen ?? null,
            'comuna' => $empresa->CmnaOrigen ?? null,
            'ciudad' => $empresa->CiudadOrigen ?? null,
            'codigo_vendedor' => $empresa->CodigoVendedor ?? null,
            'correo' => $empresa->CorreoEmisor ?? null,
            'telefono' => $empresa->Telefono ?? null,
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
    }

    protected function getCaratula($caratula, $idEmisor): int
    {
        // Verificar si existe caratula
        $existeCaratula = DB::table('caratula')
            ->where('emisor_id', '=', $idEmisor)
            ->where('rut_envia', '=', $caratula['RutEnvia'])
            ->where('rut_receptor', '=', $caratula['RutReceptor'])
            ->get()->first();

        if (!$existeCaratula) {
            return $this->guardarCaratula($caratula, $idEmisor);
        } else return $existeCaratula->id;
    }

    protected function guardarCaratula($caratula, $idEmisor): int
    {
        return DB::table('caratula')->insertGetId([
            'emisor_id' => $idEmisor,
            'rut_envia' => $caratula['RutEnvia'],
            'rut_receptor' => $caratula['RutReceptor'],
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
    }

    protected function guardarDocumento($dteId, $cafId, $receptorId, $documento): int
    {
        if(isset($documento->Encabezado->Referencia))
            $refId = $this->getDocumento($documento->Encabezado->Referencia->TpoDocRef, $documento->Encabezado->Referencia->FolioRef);
        else
            $refId = null;
        return DB::table('documento')->insertGetId([
            'caf_id' => $cafId,
            'dte_id' => $dteId,
            'receptor_id' => $receptorId,
            // ref_id guarda el id del 'documento' de referencia
            // solo en caso de que el documento sea una nota de crédito o débito
            'ref_id' => $refId,
            'folio' => $documento->Encabezado->IdDoc->Folio ?? null,
            'tipo_transaccion' => $documento->Encabezado->IdDoc->TpoTranVenta ?? 1,
            'monto_total' => $documento->Encabezado->Totales->MntTotal ?? 0,
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
    }

    protected function getDocumento($tipo_dte, $folio)
    {
        return DB::table('documento')
            ->join('caf', 'documento.caf_id', '=', 'caf.id')
            ->where('documento.folio', $folio)
            ->where('caf.folio', $tipo_dte)
            ->select('documento.*')
            ->first()->id;
    }

    protected function guardarDetalle($detalle, $documentoId): void
    {
        DB::table('detalle')->insert([
            'documento_id' => $documentoId,
            'secuencia' => $detalle->NroLinDet,
            'nombre' => $detalle->NmbItem,
            'descripcion' => $detalle->DscItem ?? null,
            'cantidad' => $detalle->QtyItem,
            'unidad_medida' => $detalle->UnmdItem,
            'precio' => $detalle->PrcItem,
            'monto' => $detalle->MontoItem,
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
    }

    protected function getTokenBE(): void
    {
        // Solicitar seed
        $seed = file_get_contents('https://api.sii.cl/recursos/v1/boleta.electronica.semilla');
        $seed = simplexml_load_string($seed);
        $seed = (string)$seed->xpath('//SII:RESP_BODY/SEMILLA')[0];
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
            CURLOPT_POSTFIELDS => $seedSigned,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/xml',
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);

        $responseXml = simplexml_load_string($response);

        // Guardar Token con su timestamp en config.json
        $tokenBE = (string)$responseXml->xpath('//TOKEN')[0];
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $config_file->tokenBE = $tokenBE;
        $config_file->tokenBE_timestamp = Carbon::now('America/Santiago')->timestamp;
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    protected function getToken(): void
    {
        // Set ambiente certificacion
        Sii::setAmbiente(self::$ambiente);
        $token = Autenticacion::getToken($this->obtenerFirma());
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        if(in_array("39", self::$tipos_dte) || in_array("41", self::$tipos_dte))
            $config_file->tokenBE = $token;
        else
            $config_file->token = $token;
        $config_file->token_timestamp = Carbon::now('America/Santiago')->timestamp;
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    protected function isToken(): void
    {
        if (file_exists(base_path('config.json'))) {
            // Obtener config.json
            $config_file = json_decode(file_get_contents(base_path('config.json')));

            // Verificar tokenBE
            if ($config_file->tokenBE === '' || $config_file->tokenBE === false || $config_file->tokenBE_timestamp === false) {
                $this->getTokenBE();
            } else {
                $now = Carbon::now('America/Santiago')->timestamp;
                $tokenBETimeStamp = $config_file->tokenBE_timestamp;
                $diff = $now - $tokenBETimeStamp;
                if ($diff > $config_file->tokenBE_expiration) {
                    $this->getTokenBE();
                }
            }

            // Verificar token
            if ($config_file->token === '' || $config_file->token === false || $config_file->token_timestamp === false) {
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
                'file' => env("CERT_PATH"),
                //'data' => '', // contenido del archivo certificado.p12
                'pass' => env("CERT_PASS")
            ],
        ];
        return new FirmaElectronica($config['firma']);
    }

    /**
     * Recorre los documentos como array y les asigna un folio
     */
    protected function parseDte($dte): array
    {
        $documentos = [];
        $dte = json_decode(json_encode($dte), true);

        foreach ($dte["Documentos"] as $documento) {
            $modeloDocumento = $documento;

            if(!isset($modeloDocumento["Encabezado"]["IdDoc"]["TipoDTE"]))
                return ["error" => "Debe indicar el TipoDTE"];

            $tipo_dte = $modeloDocumento["Encabezado"]["IdDoc"]["TipoDTE"];

            $modeloDocumento["Encabezado"]["IdDoc"]["Folio"] = ++self::$folios[$tipo_dte];
            $documentos[] = $modeloDocumento;
        }

        // Compara si el número de folios restante en el caf es mayor o igual al número de documentos a enviar
        foreach (self::$folios as $key => $value) {
            self::$ambiente == 0 ? $tipo_dte = -$key : $tipo_dte = $key;
            $folio_final = DB::table('caf')->where('folio_id', '=', $tipo_dte)->latest()->first()->folio_final;
            $cant_folio = DB::table('secuencia_folio')->where('id', '=', $tipo_dte)->latest()->first()->cant_folios;
            $folios_restantes = $folio_final - $cant_folio;
            $folios_documentos = self::$folios[$key] - self::$folios_inicial[$key] + 1;
            if ($folios_documentos > $folios_restantes) {
                $response = [
                    'error' => 'No hay folios suficientes para generar el/los documento(s)',
                    'tipo_folio' => $tipo_dte,
                    'folios_a_utilizar' => $folios_documentos,
                    'folios_restantes' => $folios_restantes,
                    'último_folio_caf' => $folio_final,
                    'último_folio_utilizado' => $cant_folio,
                ];
            }
        }
        return $response ?? $documentos;
    }

    protected function obtenerCaratula($dte, $documentos, $firma): array
    {
        return [
            'RutEmisor' => $dte->Caratula->RutEmisor ?? $documentos[0]['Encabezado']['Emisor']['RUTEmisor'], // se obtiene automáticamente
            'RutEnvia' => $firma->getID(),
            'RutReceptor' => $dte->Caratula->RutReceptor ?? "60803000-K",
            'FchResol' => $dte->Caratula->FchResol,
            'NroResol' => $dte->Caratula->NroResol,
        ];
    }

    protected function generarEnvioDteXml(array $documentos, FirmaElectronica $Firma, array $folios, array $caratula): mixed
    {
        $EnvioDTE = new EnvioDte();
        foreach ($documentos as $documento) {
            $DTE = new Dte($documento);
            if (!$DTE->timbrar($folios[intval($DTE->getTipo())]))
                //if (!$DTE->timbrar($folios[$DTE->getTipo()]))
                break;
            if (!$DTE->firmar($Firma))
                break;
            $EnvioDTE->agregar($DTE);
        }
        $EnvioDTE->setFirma($Firma);
        $EnvioDTE->setCaratula($caratula);
        $EnvioDTE->generar();
        $errores = [];
        if ($EnvioDTE->schemaValidate()) {
            return $EnvioDTE->generar();
        } else {
            //return $EnvioDTExml = $EnvioDTE->generar();
            // si hubo errores mostrar
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return $errores;
        }
    }

    protected function obtenerFolios($dte): array
    {
        $folios = [];
        if(isset($dte->Documentos)) {
            // Obtiene los tipos dte como string
            $tipos_str = implode(", ", self::$tipos_dte);

            // Recorrer documentos
            foreach ($dte->Documentos as $documento) {
                if(isset($documento->Encabezado->IdDoc->TipoDTE)) {
                    $tipo_dte = $documento->Encabezado->IdDoc->TipoDTE;
                    if (!in_array($tipo_dte, self::$tipos_dte))
                        $error['error'][] = "El TipoDTE no es válido. Debe ser $tipos_str. Encontrado: $tipo_dte";
                    self::$ambiente == 0 ? $tipo = -$tipo_dte : $tipo = $tipo_dte;
                    try {
                        self::$folios[$tipo_dte] = DB::table('secuencia_folio')->where('id', $tipo)->value('cant_folios');
                    } catch (Exception $e){
                        $error['error'][] = "No existe la secuencia de folios con id $tipo";
                        return $error;
                    }
                    $folios[$tipo_dte] = self::$folios[$tipo_dte] + 1;
                } else $error['error'][] = "No existe el campo TipoDTE en el json";
            }
        } else {
            $error['error'][] = "No existe el campo Documentos en el json";
        }
        return $error ?? $folios;
    }

    protected function obtenerFoliosCaf(): array
    {
        $folios = [];
        foreach (self::$folios as $tipo => $cantidad) {
            self::$ambiente == 0 ? $tipo_dte = -$tipo : $tipo_dte = $tipo;
            $caf = DB::table('caf')->where('folio_id', '=', $tipo_dte)->latest()->first();
            if ($caf) {
                try {
                    $folios[$tipo] = new Folios(Storage::disk('cafs')->get($caf->xml_filename));
                } catch (Exception $e) {
                    $error['error'][] = "$e\nNo existe el caf para el folio $tipo_dte en storage";
                }
            } else {
                $error['error'][] = "No existe el caf para el folio $tipo_dte en la base de datos";
            }

        }
        return $error ?? $folios;
    }

    protected function parseFileName($rut_emisor, $rut_receptor): array
    {
        $tipo_dte = key(array_filter(self::$folios));
        $folio = self::$folios[$tipo_dte];
        $filename = "DTE_$tipo_dte" . "_$folio" . "_$this->timestamp.xml";
        $filename = str_replace(' ', 'T', $filename);
        $filename = str_replace(':', '-', $filename);
        $file = Storage::disk('dtes')->path("$rut_emisor/Envios/$rut_receptor/$filename");
        return [$file, $filename];
    }
}

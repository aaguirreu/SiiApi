<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\Sii\Autenticacion;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\Sii\Folios;
use sasco\LibreDTE\XML;
use SimpleXMLElement;
use Symfony\Component\DomCrawler\Crawler;

class DteController extends Controller
{
    protected string $timestamp;
    protected static int $retry = 10;
    protected static array $folios = [];
    protected static array $folios_inicial = [];
    protected static array $tipos_dte = [];
    protected static string $url = '';
    protected static string $url_api = ''; // se utiliza solo en boleta electronica para consultas de estado
    protected static int $ambiente = 0; // 0 Producción, 1 Certificación
    protected static string $token = '';
    protected static string $token_api; // se utiliza solo en boleta electronica para consultas de estado

    protected function actualizarFolios($id): void
    {
        foreach (self::$folios as $key => $value) {
            self::$ambiente == 0 ? $tipo = $key : $tipo = -$key;
            if (self::$folios_inicial[$key] <= self::$folios[$key])
                DB::table('secuencia_folio')->where('empresa_id', '=', $id)->where('tipo', '=', $tipo)->update(['cant_folios' => self::$folios[$key], 'updated_at' => $this->timestamp]);
        }
    }

    /**
     * @param SimpleXMLElement $caf_xml
     * @throws Exception
     * ARREGLAR: el mismo caf se puede almacenar más de una vez.
     */
    protected function uploadCaf($caf_xml, $tipo_folio, $filename, $id, $fecha_vencimiento, ?bool $forzar = false): JsonResponse
    {
        /**
         * Consulta si existe el folio en la base de datos
         * Si existe, se obtiene el último folio final y se compara con el folio inicial del caf
         * Si no existe, se guarda el caf
         */
        $folio = DB::table('caf')->where('empresa_id', '=', $id)->where('folio_id', '=', $tipo_folio);
        if ($folio) {
            if (!$forzar) {
                $folio_final = $folio->folio_final;
            } else {
                $folio_final = (int)$caf_xml->CAF->DA->RNG->D[0];
                $folio_final--;
            }
            if ($folio_final + 1 != intval($caf_xml->CAF->DA->RNG->D[0])) {
                // Si el caf no sigue el orden de folios correspondiente, no se sube.
                return response()->json([
                    'error' => 'El caf no sigue el orden de folios correspondiente. Deben ser consecutivos.',
                    'registro' => 'Último folio registrado: '.$folio_final,
                    'envío' => 'Caf recibido: '.intval($caf_xml->CAF->DA->RNG->D[0]),
                ], 400);
            }
        }

        // Si no existe la secuencia para el caf, se crea antes de ingresar el caf
        $secuencia = DB::table('secuencia_folio')->where('empresa_id', '=', $id)->where('tipo', '=', $tipo_folio)->latest()->first();
        if (!$secuencia) {
            $cant_folios = intval($caf_xml->CAF->DA->RNG->D[0]);
            $secuencia_id = DB::table('secuencia_folio')->insertGetId([
                'empresa_id' => $id,
                'tipo' => $tipo_folio,
                'cant_folios' => --$cant_folios,
                'created_at' => $this->timestamp,
                'updated_at' => $this->timestamp
            ]);
        }

        try {
            //Guardar caf en storage
            Storage::disk('xml')->put("/{$caf_xml->CAF->DA->RE[0]}/Cafs/$filename", $caf_xml->asXML());

            // Guardar en base de datos
            DB::table('caf')->insert([
                'empresa_id' => $id,
                'secuencia_id' => $secuencia->id ?? $secuencia_id,
                'tipo' => $tipo_folio,
                'folio_inicial' => $caf_xml->CAF->DA->RNG->D[0],
                'folio_final' => $caf_xml->CAF->DA->RNG->H[0],
                'fecha_vencimiento' => $fecha_vencimiento,
                'xml_filename' => $filename,
                'created_at' => $this->timestamp,
                'updated_at' => $this->timestamp
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Error al insertar caf en base de datos',
                'message' => $e->getMessage(),
            ], 400);
        }

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

    /**
     * @param $envio_dte_id
     * @param $filename
     * @param $caratula
     * @param $dte_xml
     * @return array|int
     * Función que guarda DTE en la base de datos
     * IMPORTANTE: $compra_venta = 0: Compra, 1: Venta, null: No aplica
     */
    protected function guardarXmlDB($envio_dte_id, $filename, $caratula, $dte_xml, $compra_venta): array|int
    {
        try {
            DB::beginTransaction(); // <= Starting the transaction
            $Xml = new SimpleXMLElement($dte_xml);
            $emisor_id = $this->getEmpresa($caratula['RutEmisor'], $Xml->children()->SetDTE->DTE->Documento[0]->Encabezado->Emisor);
            $receptor_id = $this->getEmpresa($caratula['RutReceptor'], $Xml->children()->SetDTE->DTE->Documento[0]->Encabezado->Receptor->RUTRecep);
            $caratula_id = $this->getCaratula($caratula, $emisor_id, $receptor_id);
            $dte_id = $this->guardarDte($filename, $envio_dte_id, $caratula_id);
            foreach ($Xml->children()->SetDTE->DTE as $dte) {
                foreach ($dte->Documento as $documento) {
                    // Si el ambiente es de certificación transformar tipo dte a negativo.
                    self::$ambiente == 0 ? $tipo_dte = $documento->Encabezado->IdDoc->TipoDTE : $tipo_dte = -$documento->Encabezado->IdDoc->TipoDTE;
                    // si envio_dte_id es null (dte recibido) no existe caf en base de datos, por lo tanto caf_id es null
                    $compra_venta == 1 ? $caf_id = DB::table('caf')
                            ->where('empresa_id', '=', $emisor_id)
                            ->where('tipo', '=', $tipo_dte)
                            ->latest()->first()->id : $caf_id = null;
                    $receptor_id = $this->getEmpresa($documento->Encabezado->Receptor->RUTRecep, $documento);
                    $documento_id = $this->guardarDocumento($dte_id, $caf_id, $receptor_id, $documento);

                    foreach ($documento->Detalle as $detalle) {
                        $this->guardarDetalle($detalle, $documento_id);
                    }
                }
            }
            DB::commit(); // <= Commit the changes
            return $dte_id;
        } catch (Exception $e) {
            report($e);
            DB::rollBack(); // <= Rollback in case of an exception
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function guardarDte($filename, $envio_dte_id, $caratula_id): int
    {
        return DB::table('dte')->insertGetId([
            'envio_id' => $envio_dte_id,
            'caratula_id' => $caratula_id,
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

    protected function getCaratula($caratula, $emisor_id, $receptor_id): int
    {
        // Verificar si existe caratula
        $existeCaratula = DB::table('caratula')
            ->where('emisor_id', '=', $emisor_id)
            ->where('receptor_id', '=', $receptor_id)
            ->where('rut_envia', '=', $caratula['RutEnvia'])
            ->get()->first();

        if (!$existeCaratula) {
            return $this->guardarCaratula($caratula, $emisor_id, $receptor_id);
        } else return $existeCaratula->id;
    }

    protected function guardarCaratula($caratula, $emisor_id, $receptor_id): int
    {
        return DB::table('caratula')->insertGetId([
            'emisor_id' => $emisor_id,
            'receptor_id' => $receptor_id,
            'rut_envia' => $caratula['RutEnvia'],
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp
        ]);
    }

    protected function guardarDocumento($dte_id, $cafId, $receptorId, $documento): int
    {
        if(isset($documento->Encabezado->Referencia))
            $refId = $this->getDocumento($documento->Encabezado->Referencia->TpoDocRef, $documento->Encabezado->Referencia->FolioRef);
        else
            $refId = null;
        return DB::table('documento')->insertGetId([
            'caf_id' => $cafId,
            'dte_id' => $dte_id,
            'receptor_id' => $receptorId,
            // ref_id guarda el id del 'documento' de referencia
            // solo en caso de que el documento sea una nota de crédito o débito
            'ref_id' => $refId,
            'folio' => $documento->Encabezado->IdDoc->Folio ?? null,
            'compra_venta' => $compra_venta ?? null,
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

    protected function getTokenBE($url): void
    {
        // Solicitar seed
        $url_seed = $url.'.semilla';
        $seed = file_get_contents($url_seed);
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
            CURLOPT_URL => $url.".token",
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
        //echo $response;
        $responseXml = simplexml_load_string($response);

        // Guardar Token con su timestamp en config.json
        $token = (string)$responseXml->xpath('//TOKEN')[0];
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        if ($url == 'https://apicert.sii.cl/recursos/v1/boleta.electronica') {
            $config_file->be->cert->token = $token;
            $config_file->be->cert->token_timestamp = Carbon::now('America/Santiago')->timestamp;
        }
        else if ($url == 'https://api.sii.cl/recursos/v1/boleta.electronica') {
            $config_file->be->prod->token = $token;
            $config_file->be->prod->token_timestamp = Carbon::now('America/Santiago')->timestamp;
        }
        $this->guardarConfigFile($config_file);
    }

    protected function getToken($ambiente): void
    {
        // Set ambiente certificacion
        Sii::setAmbiente($ambiente);
        $token = Autenticacion::getToken($this->obtenerFirma());
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        if ($ambiente == SII::CERTIFICACION) {
            $config_file->dte->cert->token = $token;
            $config_file->dte->cert->token_timestamp = Carbon::now('America/Santiago')->timestamp;
        } else if ($ambiente == SII::PRODUCCION) {
            $config_file->dte->prod->token = $token;
            $config_file->dte->prod->token_timestamp = Carbon::now('America/Santiago')->timestamp;
        }
        $this->guardarConfigFile($config_file);
    }

    protected function isToken(): void
    {
        if (file_exists(base_path('config.json'))) {
            // Obtener config.json
            $config_file = json_decode(file_get_contents(base_path('config.json')));
            // Verificar token Boleta Electronica
            if ($config_file->be->cert->token === '' || !$config_file->be->cert->token || !$config_file->be->cert->token_timestamp)
                // Obtener token de boleta electrónica certificación
                $this->getTokenBE('https://apicert.sii.cl/recursos/v1/boleta.electronica');
            else if ($config_file->be->prod->token === '' || !$config_file->be->prod->token || !$config_file->be->prod->token_timestamp)
                    // Obtener token de boleta electrónica certificación y producción
                $this->getTokenBE('https://api.sii.cl/recursos/v1/boleta.electronica');
            else {
                $now = Carbon::now('America/Santiago')->timestamp;
                $diff = $now - $config_file->be->cert->token_timestamp;
                if ($diff > $config_file->be->cert->token_expiration) {
                    $this->getTokenBE('https://apicert.sii.cl/recursos/v1/boleta.electronica'); // certificación
                }
                $diff = $now - $config_file->be->prod->token_timestamp;
                if ($diff > $config_file->be->prod->token_expiration) {
                    $this->getTokenBE('https://api.sii.cl/recursos/v1/boleta.electronica'); // producción
                }
            }

            // Verificar token DTE
            if ($config_file->dte->cert->token === '' || !$config_file->dte->cert->token || !$config_file->dte->cert->token_timestamp)
                $this->getToken(SII::CERTIFICACION);
            else if ($config_file->dte->prod->token === '' || !$config_file->dte->prod->token || !$config_file->dte->prod->token_timestamp)
                $this->getToken(SII::PRODUCCION);
            else {
                $now = Carbon::now('America/Santiago')->timestamp;
                $diff = $now - $config_file->dte->cert->token_timestamp;
                if ($diff > $config_file->dte->cert->token_expiration) {
                    $this->getToken(SII::CERTIFICACION);
                }
                $diff = $now - $config_file->dte->prod->token_timestamp;
                if ($diff > $config_file->dte->prod->token_expiration) {
                    $this->getToken(SII::PRODUCCION);
                }
            }
        } else {
            file_put_contents(base_path('config.json'), json_encode([
                'dte' => [
                    'prod' => [
                        'token' => '',
                        'token_timestamp' => '',
                        'token_expiration' => 3600
                    ],
                    'cert' => [
                        'token' => '',
                        'token_timestamp' => '',
                        'token_expiration' => 3600
                    ]
                ],
                'be' => [
                    'prod' => [
                        'token' => '',
                        'token_timestamp' => '',
                        'token_expiration' => 3600
                    ],
                    'cert' => [
                        'token' => '',
                        'token_timestamp' => '',
                        'token_expiration' => 3600
                    ]
                ]
            ], JSON_PRETTY_PRINT));

            // Obtener token de boleta electrónica certificación y producción
            $this->getToken(SII::CERTIFICACION);
            $this->getToken(SII::PRODUCCION);

            $this->getTokenBE('https://apicert.sii.cl/recursos/v1/boleta.electronica'); // certificación
            $this->getTokenBE('https://api.sii.cl/recursos/v1/boleta.electronica'); // producción
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

    protected function guardarConfigFile($config_file): void
    {
        file_put_contents(base_path('config.json'), json_encode($config_file,JSON_PRETTY_PRINT));
    }

    /**
     * Recorre los documentos como array y les asigna un folio
     */
    protected function parseDte($dte, $id): array
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
            self::$ambiente == 0 ? $tipo_dte = $key : $tipo_dte = -$key;
            $caf = DB::table('caf')->where('empresa_id', '=', $id)->where('tipo', '=', $tipo_dte)->latest()->first();
            $folio_final = $caf->folio_final;
            $cant_folio = DB::table('secuencia_folio')->where('id', '=', $caf->secuencia_id)->latest()->first()->cant_folios;
            $folios_restantes = $folio_final - $cant_folio;
            $folios_documentos = self::$folios[$key] - self::$folios_inicial[$key] + 1;
            if ($folios_documentos > $folios_restantes && $folios_restantes != 0) {
                $response = [
                    'error' => 'No hay folios suficientes para generar el/los documento(s)',
                    'tipo_folio' => $tipo_dte,
                    'folios_a_utilizar' => $folios_documentos,
                    'folios_restantes' => $folios_restantes,
                    'último_folio_caf' => $folio_final,
                    'último_folio_utilizado' => $cant_folio,
                ];
                // Si no quedan folios y la secuencia de folios está correcta, obtener nuevo caf del SII
            } elseif ($folios_restantes == 0 && $folio_final == $cant_folio) {
                try {
                    // Obtener nuevo caf del SII
                    list($rut_emp, $dv_emp) = explode('-', str_replace('.', '', $dte->Caratula->RutEmisor));
                    $caf_xml = $this->generarNuevoCaf($rut_emp, $dv_emp, $tipo_dte);
                    $caf_xml = new SimpleXMLElement($caf_xml);

                    // Calcular fecha de vencimiento a 6 meses de la fecha de autorización
                    $fecha_vencimiento = Carbon::parse($caf_xml->CAF->DA->FA[0], 'America/Santiago')->addMonths(6)->format('Y-m-d');

                    // Nombre caf tipodte_timestamp.xml
                    $filename = "F{$tipo_dte}_RNG{$caf_xml->CAF->DA->RNG->D[0]}-{$caf_xml->CAF->DA->RNG->H[0]}_FA{$caf_xml->CAF->DA->FA[0]}.xml";

                    $response = $this->uploadCaf($caf_xml, $tipo_dte, $filename, $id, $fecha_vencimiento);

                } catch (GuzzleException $e) {
                    $response = [
                        'error' => 'Error al obtener un nuevo caf del SII',
                        'message' => $e->getMessage(),
                    ];
                } catch (Exception $e) {
                    $response = [
                        'error' => 'Error al guardar nuevo caf obtenido del SII',
                        'message' => $e->getMessage(),
                    ];
                }
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

    protected function obtenerFolios($dte, $id): array
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
                    self::$ambiente == 0 ? $tipo = $tipo_dte : $tipo = -$tipo_dte;
                    try {
                        self::$folios[$tipo_dte] = DB::table('secuencia_folio')->where('empresa_id', '=', $id)->where('tipo', '=', $tipo)->value('cant_folios');
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

    protected function obtenerFoliosCaf($id, $rut): array
    {
        $folios = [];
        foreach (self::$folios as $tipo => $cantidad) {
            self::$ambiente == 0 ? $tipo_dte = $tipo : $tipo_dte = -$tipo;
            $caf = DB::table('caf')->where('empresa_id', '=', $id)->where('tipo', '=', $tipo_dte)->latest()->first();
            if ($caf) {
                try {
                    $folios[$tipo] = new Folios(Storage::disk('xml')->get("$rut/Cafs/$caf->xml_filename"));
                } catch (Exception $e) {
                    $error['error'][] = "$e\nNo existe el caf para el dte de tipo $tipo_dte en storage";
                }
            } else {
                $error['error'][] = "No existe el caf para el dte de tipo $tipo_dte en la base de datos";
            }

        }
        return $error ?? $folios;
    }

    protected function parseFileName($rut_emisor, $rut_receptor, $dte): array
    {
        $Xml = new SimpleXMLElement($dte);
        //$tipo_dte = key(array_filter(self::$folios));
        $tipo_dte = $Xml->children()->SetDTE->DTE->Documento[0]->Encabezado->TipoDTE;
        //$folio = self::$folios[$tipo_dte];
        $folio = $Xml->children()->SetDTE->DTE->Documento[0]->Encabezado->Folio;
        $filename = "DTE_$tipo_dte" . "_$folio" . "_$this->timestamp.xml";
        $filename = str_replace(' ', 'T', $filename);
        $filename = str_replace(':', '-', $filename);
        $file = Storage::disk('xml')->path("$rut_emisor/Envios/$rut_receptor/$filename");
        return [$file, $filename];
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    protected function generarNuevoCaf($rut_emp, $dv_emp, $tipo_folio): string
    {
        $header = [
            //'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0',
            'Cookie' => json_decode(file_get_contents(base_path('cookies.json')))->cookies,
        ];

        // Guzzle client que guarda las cookies de la sesión
        $client = new Client(array(
            'cookies' => true,
        ));

        // Solicitar folios
        $data_solicitar_folios = $this->getDataSolicitarFolios($rut_emp, $dv_emp, $tipo_folio);
        $response = $client->request('POST', 'https://maullin.sii.cl/cvc_cgi/dte/of_solicita_folios_dcto', [
            'headers' => $header,
            'form_params' => $data_solicitar_folios,
            'verify' => false
        ]);

        //echo $response->getBody()->getContents();

        if(!$this->verificarRespuestaCaf($response->getBody()->getContents()))
            throw new Exception(Log::read());

        // Confirmar folio
        try {
            $data_confirmar_folio = $this->getDataConfirmarFolio($rut_emp, $dv_emp, $tipo_folio, $response->getBody()->getContents());
        } catch (InvalidArgumentException $e) {
            throw new Exception("Rut incorrecto o no autorizado");
        }
        $response = $client->request('POST', 'https://maullin.sii.cl/cvc_cgi/dte/of_confirma_folio', [
            'headers' => $header,
            'form_params' => $data_confirmar_folio,
        ]);

        $data_obtener_folio = $this->getDataObtenerFolio($response->getBody()->getContents());

        // Generar folios. Necesario para que el SII genere el archivo xml (caf)
        $response = $client->request('POST', 'https://maullin.sii.cl/cvc_cgi/dte/of_genera_folio', [
            'headers' => $header,
            'form_params' => $data_obtener_folio,
        ]);

        // Generar archivo xml (caf)
        $data_generar_archivo = $this->getDataGenerarArchivo($rut_emp, $dv_emp, $data_obtener_folio);
        $response = $client->request('POST', 'https://maullin.sii.cl/cvc_cgi/dte/of_genera_archivo', [
            'headers' => $header,
            'form_params' => $data_generar_archivo,
        ]);

        return $response->getBody()->getContents();
    }

    protected function getDataSolicitarFolios($rut_emp, $dv_emp, $tipo_folio): array
    {
        return [
            'RUT_EMP' => $rut_emp,
            'DV_EMP' => $dv_emp,
            'COD_DOCTO' => $tipo_folio,
            'ACEPTAR' => 'Continuar',
        ];
    }

    protected function getDataConfirmarFolio($rut_emp, $dv_emp, $tipo_folio, $html): array
    {
        // Obtener el contenido HTML de la respuesta
        $crawler = new Crawler($html);
        $max_autor = $crawler->filter('input[name="MAX_AUTOR"]')->attr('value');
        $afecto_iva = $crawler->filter('input[name="AFECTO_IVA"]')->attr('value');
        $anotacion = $crawler->filter('input[name="ANOTACION"]')->attr('value');
        $con_credito = $crawler->filter('input[name="CON_CREDITO"]')->attr('value');
        $con_ajuste = $crawler->filter('input[name="CON_AJUSTE"]')->attr('value');
        $factor = $crawler->filter('input[name="FACTOR"]')->attr('value');
        $ult_timbraje = $crawler->filter('input[name="ULT_TIMBRAJE"]')->attr('value');
        $con_historia = $crawler->filter('input[name="CON_HISTORIA"]')->attr('value');
        $folio_ini_cre = $crawler->filter('input[name="FOLIO_INICRE"]')->attr('value');
        $folio_fin_cre = $crawler->filter('input[name="FOLIO_FINCRE"]')->attr('value');
        $fecha_ant = $crawler->filter('input[name="FECHA_ANT"]')->attr('value');
        $estado_timbraje = $crawler->filter('input[name="ESTADO_TIMBRAJE"]')->attr('value');
        $control = $crawler->filter('input[name="CONTROL"]')->attr('value');
        $cant_timbrajes = $crawler->filter('input[name="CANT_TIMBRAJES"]')->attr('value');
        $folio_inicial = $crawler->filter('input[name="FOLIO_INICIAL"]')->attr('value');
        $folios_disp = $crawler->filter('input[name="FOLIOS_DISP"]')->attr('value');

        // Agregar el valor de 'MAX_AUTOR' al array de datos
        return [
            'RUT_EMP' => $rut_emp,
            'DV_EMP' => $dv_emp,
            'FOLIO_INICIAL' => $folio_inicial,
            'COD_DOCTO' => $tipo_folio,
            'AFECTO_IVA' => $afecto_iva,
            'ANOTACION' => $anotacion,
            'CON_CREDITO' => $con_credito,
            'CON_AJUSTE' => $con_ajuste,
            'FACTOR' => $factor,
            'MAX_AUTOR' => $max_autor,
            'ULT_TIMBRAJE' => $ult_timbraje,
            'CON_HISTORIA' => $con_historia,
            'FOLIO_INICRE' => $folio_ini_cre,
            'FOLIO_FINCRE' => $folio_fin_cre,
            'FECHA_ANT' => $fecha_ant,
            'ESTADO_TIMBRAJE' => $estado_timbraje,
            'CONTROL' => $control,
            'CANT_TIMBRAJES' => $cant_timbrajes,
            'CANT_DOCTOS' => $max_autor,
            'ACEPTAR' => 'Solicitar Numeración',
            'FOLIOS_DISP' => $folios_disp
        ];
    }

    private function getDataObtenerFolio($html): array
    {
        // Obtener el contenido HTML de la respuesta
        $crawler = new Crawler($html);
        $nomusu = $crawler->filter('input[name="NOMUSU"]')->attr('value');
        $con_credito = $crawler->filter('input[name="CON_CREDITO"]')->attr('value');
        $con_ajuste = $crawler->filter('input[name="CON_AJUSTE"]')->attr('value');
        $folios_disp = $crawler->filter('input[name="FOLIOS_DISP"]')->attr('value');
        $max_autor = $crawler->filter('input[name="MAX_AUTOR"]')->attr('value');
        $ult_timbraje = $crawler->filter('input[name="ULT_TIMBRAJE"]')->attr('value');
        $con_historia = $crawler->filter('input[name="CON_HISTORIA"]')->attr('value');
        $cant_timbrajes = $crawler->filter('input[name="CANT_TIMBRAJES"]')->attr('value');
        $folio_ini_cre = $crawler->filter('input[name="FOLIO_INICRE"]')->attr('value');
        $folio_fin_cre = $crawler->filter('input[name="FOLIO_FINCRE"]')->attr('value');
        $fecha_ant = $crawler->filter('input[name="FECHA_ANT"]')->attr('value');
        $estado_timbraje = $crawler->filter('input[name="ESTADO_TIMBRAJE"]')->attr('value');
        $control = $crawler->filter('input[name="CONTROL"]')->attr('value');
        $folio_ini = $crawler->filter('input[name="FOLIO_INI"]')->attr('value');
        $folio_fin = $crawler->filter('input[name="FOLIO_FIN"]')->attr('value');
        $dia = $crawler->filter('input[name="DIA"]')->attr('value');
        $mes = $crawler->filter('input[name="MES"]')->attr('value');
        $ano = $crawler->filter('input[name="ANO"]')->attr('value');
        $hora = $crawler->filter('input[name="HORA"]')->attr('value');
        $minuto = $crawler->filter('input[name="MINUTO"]')->attr('value');
        $rut_emp = $crawler->filter('input[name="RUT_EMP"]')->attr('value');
        $dv_emp = $crawler->filter('input[name="DV_EMP"]')->attr('value');
        $cod_docto = $crawler->filter('input[name="COD_DOCTO"]')->attr('value');
        $cant_doctos = $crawler->filter('input[name="CANT_DOCTOS"]')->attr('value');

        return [
            'NOMUSU' => $nomusu,
            'CON_CREDITO' => $con_credito,
            'CON_AJUSTE' => $con_ajuste,
            'FOLIOS_DISP' => $folios_disp,
            'MAX_AUTOR' => $max_autor,
            'ULT_TIMBRAJE' => $ult_timbraje,
            'CON_HISTORIA' => $con_historia,
            'CANT_TIMBRAJES' => $cant_timbrajes,
            'CON_AJUSTE' => $con_ajuste,
            'FOLIO_INICRE' => $folio_ini_cre,
            'FOLIO_FINCRE' => $folio_fin_cre,
            'FECHA_ANT' => $fecha_ant,
            'ESTADO_TIMBRAJE' => $estado_timbraje,
            'CONTROL' => $control,
            'FOLIO_INI' => $folio_ini,
            'FOLIO_FIN' => $folio_fin,
            'DIA' => $dia,
            'MES' => $mes,
            'ANO' => $ano,
            'HORA' => $hora,
            'MINUTO' => $minuto,
            'RUT_EMP' => $rut_emp,
            'DV_EMP' => $dv_emp,
            'COD_DOCTO' => $cod_docto,
            'CANT_DOCTOS' => $cant_doctos,
            'ACEPTAR' => 'Obtener Folios'
        ];
    }

    private function getDataGenerarArchivo($rut_emp, $dv_emp, $data): array
    {
        return [
            'RUT_EMP' => $rut_emp,
            'DV_EMP' => $dv_emp,
            'COD_DOCTO' => $data['cod_docto'],
            'FOLIO_INI' => $data['folio_ini'],
            'FOLIO_FIN' => $data['folio_fin'],
            'FECHA' => $data['ano'] . '-' . $data['mes'] . '-' . $data['dia'],
            'ACEPTAR' => 'AQUI'
        ];
    }

    /**
     * @param $ambiente
     * @return void
     * Obtiene el ambiente y setea las variables de clase correspondientes
     * Tanto el envío de boleta electronica como DTEs utilizan el token obtenido desde el servicio de DTEs por algún error en el SII
     * En el caso de consultas de estado de boletas electronicas se utiliza el token obtenido desde el servicio de boletas electronicas
     */
    public function setAmbiente($ambiente): void
    {
        // Servicio Boleta Electronica
        if(in_array("39", self::$tipos_dte) || in_array("41", self::$tipos_dte)) {
            if ($ambiente == "certificacion" || $ambiente == 1) {
                self::$ambiente = 1;
                self::$url = 'https://pangal.sii.cl/recursos/v1/boleta.electronica.envio'; // url certificación ENVIO BOLETAS
                self::$url_api = 'https://apicert.sii.cl/recursos/v1/boleta.electronica'; // url certificación CONSULTAS BOLETAS

                // IMPORTANTE: token debería ser el obtenido desde la api de boletas electronicas:
                // self::$token = json_decode(file_get_contents(base_path('config.json')))->be->cert->token;
                // pero solo funciona con el token de DTE's certificación/produccion
                // Esto solo sucede con el envío de boletas, no con la consulta de estado de boletas
                self::$token = json_decode(file_get_contents(base_path('config.json')))->dte->cert->token;
                // Token para consultas de estado de boletas
                self::$token_api = json_decode(file_get_contents(base_path('config.json')))->be->cert->token;
            } else if ($ambiente == "produccion" || $ambiente == 0) {
                self::$ambiente = 0;
                self::$url = 'https://rahue.sii.cl/recursos/v1/boleta.electronica.envio'; // url producción ENVIO BOLETAS
                self::$url_api = 'https://api.sii.cl/recursos/v1/boleta.electronica'; // url producción CONSULTAS BOLETAS
                // IMPORTANTE: token debería ser el obtenido desde la api de boletas electronicas:
                // self::$token = json_decode(file_get_contents(base_path('config.json')))->be->prod->token;
                // pero solo funciona con el token de DTE's certificación/produccion
                self::$token = json_decode(file_get_contents(base_path('config.json')))->dte->prod->token;
                // Token para consultas de estado de boletas
                self::$token_api = json_decode(file_get_contents(base_path('config.json')))->be->prod->token;
            }
            else abort(404);
        } else { // Servicio DTEs
            if ($ambiente == "certificacion"  || $ambiente == 1) {
                self::$ambiente = 1;
                self::$url = 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload'; // url certificación
                self::$token = json_decode(file_get_contents(base_path('config.json')))->dte->cert->token;
            } else if ($ambiente == "produccion" || $ambiente == 0) {
                self::$ambiente = 0;
                self::$url = 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload'; // url producción
                self::$token = json_decode(file_get_contents(base_path('config.json')))->dte->prod->token;
            }
            else abort(404);
        }
    }

    private function verificarRespuestaCaf($html): bool
    {
        // Obtener el contenido HTML de la respuesta
        $crawler = new Crawler($html);

        echo $crawler->filter('font[class="texto"]')->last()->text();

        if (stristr($crawler->filter('font[class="texto"]')->last()->text(), "NO SE AUTORIZA TIMBRAJE ELECTRÓNICO")){
            Log::write($crawler->filter('font[class="texto"]')->last()->text());
            return false;
        }

        return true;
    }

    public function getTokenMethod(?string $tipo_dte): string
    {
        if ($tipo_dte == "39" || $tipo_dte == "41") {
            return self::$token_api;
        } else {
            return self::$token;
        }
    }
}

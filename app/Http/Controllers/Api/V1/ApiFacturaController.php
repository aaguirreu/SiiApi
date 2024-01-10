<?php

namespace App\Http\Controllers\Api\V1;

use App\Mail\DteEnvio;
use App\Mail\DteResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Exception;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use SimpleXMLElement;

// Debería ser class ApiFacturaController extends ApiController
// y llamar a FacturaController con use FacturaController, new FacturaController(construct)
class ApiFacturaController extends FacturaController
{
    public function __construct()
    {
        parent::__construct([33, 34, 56, 61]);
        $this->timestamp = Carbon::now('America/Santiago');
    }

    /**
     * @param Request $request
     * @param $ambiente
     * @return JsonResponse
     * Enviar DTE al SII y guardar en base de datos
     * LLama a la faunción enviarDteReceptor
     */
    public function envioDte(Request $request, $ambiente): JsonResponse
    {
        // Leer string como json
        $dte = json_decode(json_encode($request->json()->all()));

        // Set ambiente certificacón
        $this->setAmbiente($ambiente);

        // Obtiene los folios con la cantidad de folios usados desde la base de datos
        self::$folios_inicial = $this->obtenerFolios($dte);
        if (isset(self::$folios_inicial['error'])) {
            return response()->json([
                'message' => "Error al obtener tipo de folios",
                'errores' => self::$folios_inicial['error']
            ], 400);
        }

        // Obtener folios del Caf
        $folios = $this->obtenerFoliosCaf();
        if (isset($folios['error'])) {
            return response()->json([
                'message' => "Error al obtener folios desde el CAF",
                'errores' => $folios['error']
            ], 400);
        }

        // Verificar si existe correo electrónico en la base de datos
        $correo = $this->obtenerCorreoDB($dte->Documentos[0]->Encabezado->Receptor->RUTRecep);
        if(!isset($correo)) {
            // Verificar si existe correo electrónico en el envío
            if (!isset($dte->Documentos[0]->Encabezado->Receptor->CorreoRecep)) {
                return response()->json([
                    'errores' => "No se ha encontrado el correo electrónico del receptor",
                    'message' => "Agregue CorreoRecep en el envío o agregue/actualice al receptor en la base de datos"
                ], 400);
            } else {
                $correo = $dte->Documentos[0]->Encabezado->Receptor->CorreoRecep;
                $this->actualizarCorreoDB($dte->Documentos[0]->Encabezado->Receptor->RUTRecep, $correo);
            }
        }

        // Parseo de boletas según modelo libreDTE
        $documentos = $this->parseDte($dte);
        if (isset($documentos['error'])) {
            return response()->json([
                'message' => "Error al parsear la boleta electrónica",
                'errores' => json_decode(json_encode($documentos))
            ], 400);
        }

        // Objetos de Firma y Folios
        $firma = $this->obtenerFirma();

        // Obtener caratula
        $caratula = $this->obtenerCaratula($dte, $documentos, $firma);

        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $envio_dte_xml = $this->generarEnvioDteXml($documentos, $firma, $folios, $caratula);
        if(is_array($envio_dte_xml)){
            return response()->json([
                'message' => "Error al generar el envio de DTEs",
                'errores' => json_decode(json_encode($envio_dte_xml)),
            ], 400);
        }

        // Enviar DTE al SII e insertar en base de datos de ser exitoso
        list($envio_response, $filename) = $this->enviar($caratula['RutEnvia'], $caratula['RutEmisor'] , "60803000-K", $envio_dte_xml);
        if (!$envio_response) {
            return response()->json([
                'message' => "Error al enviar el DTE",
                'errores' => Log::read()->msg,
            ], 400);
        }

        if($envio_response->STATUS != '0') {
            return response()->json([
                'message' => "Error en la respuesta del SII al enviar dte",
                'errores' => $envio_response,
            ], 400);
        }

        $envioDteId = $this->guardarEnvioDte($envio_response);
        // Guardar en base de datos envio, xml, etc
        $dbresponse = $this->guardarXmlDB($envioDteId, $filename, $caratula, $envio_dte_xml);
        if (isset($dbresponse['error'])) {
            return response()->json([
                'message' => "Error al guardar el DTE en la base de datos",
                'errores' => $dbresponse['error'],
            ], 400);
        }

        // Cambiar RutReceptor de caratula
        $caratula['RutReceptor'] = $dte->Documentos[0]->Encabezado->Receptor->RUTRecep;

        // Enviar DTE a receptor
        return $this->enviarDteReceptor($documentos, $dte->Documentos[0], $firma, $folios, $caratula, $correo, $envio_response);
    }

    /**
     * @param $documentos
     * @param $doc
     * @param $firma
     * @param $folios
     * @param $caratula
     * @param $correo
     * @param $envio_response
     * @return JsonResponse
     * Enviar DTE al receptor y guardar en base de datos
     */
    private function enviarDteReceptor($documentos, $doc, $firma, $folios, $caratula, $correo, $envio_response)
    {
        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $envio_dte_xml = $this->generarEnvioDteXml($documentos, $firma, $folios, $caratula);
        if(is_array($envio_dte_xml)){
            return response()->json([
                'message' => "DTE enviado correctamente al SII pero NO al receptor",
                'response' => [
                    'EnvioSii' => $envio_response,
                    'EnvioReceptor' => [
                        'message' => "Error al generar el envio de DTEs",
                        'errores' => json_decode(json_encode($envio_dte_xml)),
                    ]
                ],
            ], 400);
        }

        // Guardar en Storage
        if (!str_contains($envio_dte_xml, '<?xml')) {
            $envio_dte_xml = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $envio_dte_xml;
        }
        do {
            list($file, $filename) = $this->parseFileName($caratula['RutEmisor'], $caratula['RutReceptor']);
        } while (file_exists($file));

        if(!Storage::disk('dtes')->put("{$caratula['RutEmisor']}/Envios/{$caratula['RutReceptor']}/$filename", $envio_dte_xml)) {
            Log::write(0, 'Error al guardar dte en Storage');
            return response()->json([
                'message' => "DTE enviado correctamente al SII pero NO al receptor",
                'response' => [
                    'EnvioSii' => $envio_response,
                    'EnvioReceptor' => [
                        'message' => "Error al guardar el DTE en el Storage",
                    ]
                ],
            ], 400);
        }

        // Enviar respuesta por correo
        $message = [
            'from' => DB::table('empresa')->where('rut', '=', $caratula['RutEmisor'])->first()->razon_social,
        ];

        $fileEnvio = [
            'filename' => $filename,
            'data' => $envio_dte_xml
        ];

        try {
            Mail::to($correo)->send(new DteEnvio($message, $fileEnvio));
        } catch (\Exception $e) {
            Log::write(0, 'Error al enviar dte por correo');
            return response()->json([
                'message' => "DTE enviado correctamente al SII pero NO al receptor",
                'response' => [
                    'EnvioSii' => $envio_response,
                    'EnvioReceptor' => [
                        'message' => "Error al enviar dte por correo",
                    ]
                ],
            ], 400);
        }

        // Actualizar folios en la base de datos
        // $this->actualizarFolios();

        // Guardar en base de datos solo carátula, ya que, el dte es el mismo enviado al Sii.
        $emisorID = $this->getEmpresa($caratula['RutEmisor'], $doc->Encabezado->Emisor);
        $caratulaId = $this->getCaratula($caratula, $emisorID);

        return response()->json([
            'message' => "DTE enviado correctamente",
            'response' => [
                'EnvioSii' => $envio_response,
                'EnvioReceptor' => [
                    'Estado' => "Enviado"
                ]
            ],
        ], 200);
    }

    /**
     * @param Request $request
     * @param string $ambiente
     * @return bool|string
     * Estado de envío de DTE
     */
    public function estadoEnvioDte(Request $request, string $ambiente)
    {
        // Leer string como json
        $body = json_decode(json_encode($request->json()->all()));

        // Set ambiente certificacón
        $this->setAmbiente($ambiente);

        // Set ambiente certificacón (default producción)
        Sii::setAmbiente(self::$ambiente);

        $response = Sii::request('QueryEstUp', 'getEstUp', [
            $body->rut,
            $body->dv,
            $body->trackID,
            self::$token
        ]);
        // si el estado se pudo recuperar se muestra estado y glosa

        return $response->asXML();
    }


    /**
     * @param Request $request
     * @param $ambiente
     * @return bool|string
     * Estado de DTE
     */
    public function estadoDte(Request $request, $ambiente)
    {
        // Leer string como json
        $body = json_decode(json_encode($request->json()->all()));

        // Set ambiente certificacón
        $this->setAmbiente($ambiente);

        // Consulta estado dte
        // Set ambiente certificacón (default producción)
        Sii::setAmbiente(self::$ambiente);

        // consultar estado dte
        $xml = Sii::request('QueryEstDte', 'getEstDte', [
            'RutConsultante'    => $body->rut,
            'DvConsultante'     => $body->dv,
            'RutCompania'       => $body->rut,
            'DvCompania'        => $body->dv,
            'RutReceptor'       => $body->rutReceptor,
            'DvReceptor'        => $body->dvReceptor,
            'TipoDte'           => $body->tipo,
            'FolioDte'          => $body->folio,
            'FechaEmisionDte'   => $body->fechaEmision,
            'MontoDte'          => $body->monto,
            'token'             => self::$token,
        ]);

        return $xml->asXML();
    }

    public function subirCaf(Request $request): JsonResponse
    {
        return $this->uploadCaf($request);
    }

    public function forzarSubirCaf(Request $request): JsonResponse
    {
        return $this->uploadCaf($request, true);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * Envia RespuestaDTE con el estado de aceptado o rechazado
     */
    public function enviarRespuestaDocumento(Request $request): JsonResponse
    {
        // Leer string como json
        $body = json_decode(json_encode($request->json()->all()));

        // Verificar si existe el dte en DB
        $dte = DB::table('dte')->where('id', '=', $body->dteId)->first();
        if (!$dte) {
            return response()->json([
                'message' => "Error al enviar respuesta de documento",
                'error' => "No se ha encontrado el documento",
            ], 400);
        }

        if ($dte->estado != null) {
            return response()->json([
                'message' => "Error al enviar respuesta de documento",
                'error' => "El documento ya ha sido respondido",
                'estado' => \sasco\LibreDTE\Sii\RespuestaEnvio::$estados['respuesta_documento'][$dte->estado],
            ], 400);
        }

        // Obtener filename del dte con su id
        $filename = $dte->xml_filename;
        try {
            $caratula = DB::table('empresa')
                ->join('caratula', 'empresa.id', '=', 'caratula.emisor_id')
                ->where('caratula.id', '=', $dte->caratula_id)
                ->select('caratula.*', 'empresa.rut as rut_emisor')
                ->first();
            $dte_xml = Storage::disk('dtes')->get("$caratula->rut_receptor/Recibidos/$caratula->rut_emisor/$filename");
        } catch (\Exception $e) {
            return response()->json([
                'message' => "Error al enviar respuesta de documento",
                'error' => $e->getMessage(),
            ], 400);
        }

        $motivo = match ($body->estado) {
            0 => ".",
            2, 1 => ". $body->motivo",
            default => false,
        };

        if (!$motivo){
            return response()->json([
                'message' => "Error al enviar respuesta de documento",
                'error' => "Estado no válido",
            ], 400);
        }

        // Obtener respuesta de documento
        $respuesta = $this->respuestaDocumento($body->dteId, $body->estado, $motivo, $dte_xml);

        if (isset($respuesta['error'])) {
            return response()->json([
                'message' => "Error al enviar respuesta de documento",
                'error' => $respuesta['error'],
            ], 400);
        }

        $dte_xml = new SimpleXMLElement($dte_xml);
        // Enviar respuesta por correo
        Mail::to($body->correo)->send(new DteResponse($dte_xml->children()->SetDTE->DTE->Documento[0]->Encabezado->Emisor->RznSoc, $respuesta));

        // Actualizar estado del dte en base de datos
        DB::table('dte')
            ->where('id', '=', $body->dteId)
            ->update(['estado' => $body->estado]);

        return response()->json([
            'message' => "Respuesta de documento enviada correctamente",
        ], 200);
    }

    public function agregarCliente(Request $request): JsonResponse {
        // Leer string como json
        $body = json_decode(json_encode($request->json()->all()));

        // Verificar si existe el cliente en DB
        $cliente = DB::table('cliente')->where('empresa_id', '=', $body->empresa_id)->first();
        if ($cliente) {
            return response()->json([
                'message' => "Error al agregar cliente",
                'error' => "El cliente ya existe",
            ], 400);
        } else {
            try {
                $id_cliente = DB::table('cliente')->insertGetId([
                    'empresa_id' => $body->empresa_id,
                    'created_at' => $this->timestamp,
                    'updated_at' => $this->timestamp,
                ]);

                return response()->json([
                    'message' => "Cliente agregado correctamente",
                ], 200);

            } catch (Exception $e) {
                return response()->json([
                    'message' => "Error al agregar cliente",
                    'error' => $e->getMessage(),
                ], 400);
            }
        }
    }

    /**
     * @param $ambiente
     * @return void
     * Set ambiente certificacón o producción
     */
    protected function setAmbiente($ambiente) {
        if ($ambiente == "certificacion") {
            self::$ambiente = 0;
            self::$url = 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload'; // url certificación
        } else if ($ambiente == "produccion") {
            self::$ambiente = 1;
            self::$url = 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload'; // url producción
        }
        else abort(404);
    }
}

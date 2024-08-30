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
use Exception;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use SimpleXMLElement;


class ApiFacturaController extends FacturaController
{
    public function __construct()
    {
        parent::__construct([33, 34, 52, 56, 61]);
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

        // Set ambiente
        $this->setAmbiente($ambiente);

        // Verificar si existe empresa
        $empresa = DB::table('empresa')->where('rut', '=', $dte->Documentos[0]->Encabezado->Emisor->RUTEmisor)->first();
        if (!$empresa) {
            return response()->json([
                'message' => "Error al encontrar la empresa",
                'error' => "No existe empresa con el rut " . $dte->Documentos[0]->Encabezado->Emisor->RUTEmisor,
            ], 400);
        }

        // Verificar la empresa es cliente
        $cliente = DB::table('cliente')->where('empresa_id', '=', $empresa->id)->first();
        if (!$cliente) {
            return response()->json([
                'message' => "Error al encontrar el cliente",
                'error' => "No existe cliente con el rut " . $empresa->rut,
            ], 400);
        }

        // Obtiene los folios con la cantidad de folios usados desde la base de datos
        self::$folios_inicial = $this->obtenerFolios($dte, $empresa->id);
        if (isset(self::$folios_inicial['error'])) {
            return response()->json([
                'message' => "Error al obtener tipo de folios",
                'errores' => self::$folios_inicial['error']
            ], 400);
        }

        // Obtener folios del Caf
        $folios = $this->obtenerFoliosCaf($empresa->id, $empresa->rut);
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
            if (isset($dte->Documentos[0]->Encabezado->Receptor->CorreoRecep)) {
                $correo = $dte->Documentos[0]->Encabezado->Receptor->CorreoRecep;
                $this->actualizarCorreoDB($dte->Documentos[0]->Encabezado->Receptor->RUTRecep, $correo);
            }
        }

        // Parseo de boletas según modelo libreDTE
        $documentos = $this->parseDte($dte, $empresa->id);
        if (isset($documentos['error'])) {
            return response()->json([
                'message' => "Error al parsear el dte",
                'errores' => json_decode(json_encode($documentos))
            ], 400);
        }

        // Objetos de Firma y Folios
        $firma = $this->obtenerFirma($dte->Caratula->RutEnvia);

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
        list($envio_response, $filename) = $this->enviar($envio_dte_xml, $caratula['RutEnvia'], $caratula['RutEmisor'], "60803000-K");
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

        // Guardar en tabla envio_dte
        // Se separa de guardarXmlDB por que esta función se utiliza para guardar compras y ventas
        // los dte recibidos (compras) no tienen envio_id
        $envioDteId = $this->guardarEnvioDte($envio_response);

        // Guardar en base de datos envio, xml, etc. Venta (1)
        $dbresponse = $this->guardarXmlDB($envioDteId, $filename, $caratula, $envio_dte_xml, 1);
        if (isset($dbresponse['error'])) {
            return response()->json([
                'message' => "Error al guardar el DTE en la base de datos",
                'errores' => $dbresponse['error'],
            ], 400);
        }

        // Actualizar folios en la base de datos
        $this->actualizarFolios($empresa->id);

        // Cambiar RutReceptor de caratula
        $caratula['RutReceptor'] = $dte->Documentos[0]->Encabezado->Receptor->RUTRecep;

        // Enviar DTE a receptor
        if($correo)
            return $this->enviarDteReceptor($documentos, $dte->Documentos[0], $firma, $folios, $caratula, $correo, $envio_response);
        else
            return response()->json([
                'message' => "DTE enviado correctamente al SII pero NO al receptor",
                'response' => [
                    'envio_sii' => $envio_response,
                    'envio_receptor' => [
                        'message' => "No se ha encontrado el correo del receptor",
                    ]
                ],
            ], 200);
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
    private function enviarDteReceptor($documentos, $doc, $firma, $folios, $caratula, $correo, $envio_response): JsonResponse
    {
        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $envio_dte_xml = $this->generarEnvioDteXml($documentos, $firma, $folios, $caratula);
        if(is_array($envio_dte_xml)){
            return response()->json([
                'message' => "DTE enviado correctamente al SII pero NO al receptor",
                'response' => [
                    'envio_sii' => $envio_response,
                    'envio_receptor' => [
                        'message' => "Error al generar el envio de DTEs",
                        'errores' => json_decode(json_encode($envio_dte_xml)),
                    ]
                ],
            ], 200);
        }

        // Guardar en Storage
        if (!str_contains($envio_dte_xml, '<?xml')) {
            $envio_dte_xml = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $envio_dte_xml;
        }
        do {
            list($file, $filename) = $this->parseFileName($caratula['RutEmisor'], $caratula['RutReceptor'], $envio_dte_xml);
        } while (file_exists($file));

        if(!Storage::disk('xml')->put("{$caratula['RutEmisor']}/Envios/{$caratula['RutReceptor']}/$filename", $envio_dte_xml)) {
            Log::write(0, 'Error al guardar dte en Storage');
            return response()->json([
                'message' => "DTE enviado correctamente al SII pero NO al receptor",
                'response' => [
                    'envio_sii' => $envio_response,
                    'envio_receptor' => [
                        'message' => "Error al guardar el DTE en el Storage",
                    ]
                ],
            ], 200);
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
                    'envio_sii' => $envio_response,
                    'envio_receptor' => [
                        'message' => "Error al enviar dte por correo",
                    ]
                ],
            ], 200);
        }

        // Guardar en base de datos solo carátula, ya que, el dte es el mismo enviado al Sii.
        $emisor_id = $this->getEmpresa($caratula['RutEmisor'], $doc->Encabezado->Emisor);
        $receptor_id = $this->getEmpresa($caratula['RutReceptor'], $doc->Encabezado->Emisor);
        $caratulaId = $this->getCaratula($caratula, $emisor_id, $receptor_id);

        return response()->json([
            'message' => "DTE enviado correctamente",
            'response' => [
                'envio_sii' => $envio_response,
                'envio_receptor' => [
                    'message' => "Enviado"
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
        Sii::setAmbiente(self::$ambiente);
        $response = Sii::request('QueryEstUp', 'getEstUp', [
            $request->rut,
            $request->dv,
            $request->track_id,
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
    public function estadoDocumento(Request $request, $ambiente)
    {
        Sii::setAmbiente(self::$ambiente);
        // consultar estado dte
        $xml = Sii::request('QueryEstDte', 'getEstDte', [
            'RutConsultante'    => $request->rut,
            'DvConsultante'     => $request->dv,
            'RutCompania'       => $request->rut,
            'DvCompania'        => $request->dv,
            'RutReceptor'       => $request->rut_receptor,
            'DvReceptor'        => $request->dv_receptor,
            'TipoDte'           => $request->tipo,
            'FolioDte'          => $request->folio,
            'FechaEmisionDte'   => $request->fecha_emision,
            'MontoDte'          => $request->monto,
            'token'             => self::$token,
        ]);

        return $xml->asXML();
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

        // Obtener caratula de dte
        $caratula = DB::table('caratula')
            ->where('id', '=', $dte->caratula_id)->first();

        // Obtener id de caratula.rut_receptor si existe
        try {
            $receptor = DB::table('empresa')
                ->where('id', '=', $caratula->receptor_id)->first();;
        } catch (\Exception $e) {
            return response()->json([
                'message' => "Error al enviar respuesta de documento",
                'error' => "No existe el receptor de id $caratula->receptor_id en base de datos",
            ], 400);
        }

        // Verificar si RutReceptor está en la base de datos como cliente
        try {
            $cliente = DB::table('cliente')
                ->where('id', '=', $receptor->id)
                ->first();
        } catch (\Exception $e) {
            return response()->json([
                'message' => "Error al enviar respuesta de documento",
                'error' => "El receptor $caratula->rut_receptor no está registrado como cliente"
            ], 400);
        }

        // Obtener filename del dte con su id
        $filename = $dte->xml_filename;
        try {
            $emisor = DB::table('empresa')
                ->where('id', '=', $caratula->emisor_id)
                ->first();
            $dte_xml = Storage::disk('xml')->get("$receptor->rut/Recibidos/$emisor->rut/$filename");
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

        $respuesta_xml = new SimpleXMLElement($dte_xml);
        // Enviar respuesta por correo
        Mail::to($body->correo)->send(new DteResponse($respuesta_xml->children()->SetDTE->DTE->Documento[0]->Encabezado->Emisor->RznSoc, $respuesta));

        // Esto no va
        /*
        // Enviar respuesta al SII
        list($envio_response, $filename) = $this->envioRecibos($body->dteId, $body->estado, $motivo, $dte_xml);
        if (!$envio_response) {
            return response()->json([
                'message' => "Error al enviar respuesta al Sii",
                'errores' => Log::read()->msg,
            ], 400);
        }

        if($envio_response->STATUS != '0') {
            return response()->json([
                'message' => "Error en la respuesta del SII al enviar respuesta",
                'errores' => $envio_response,
            ], 400);
        }*/

        // Actualizar estado del dte en base de datos
        try {
            DB::table('dte')
                ->where('id', '=', $body->dteId)
                ->update(['estado' => $body->estado]);
        }catch (Exception $e){
            return response()->json([
                'message' => "Error al enviar respuesta de documento",
                'error' => $e->getMessage()
            ], 200);
        }

        return response()->json([
            'message' => "Respuesta de documento enviada correctamente",
        ], 200);
    }
}

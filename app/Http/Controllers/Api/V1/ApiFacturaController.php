<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;


// Debería ser class ApiFacturaController extends ApiController
// y llamar a FacturaController con use FacturaController, new FacturaController(construct)
class ApiFacturaController extends FacturaController
{
    public function __construct()
    {
        $ambiente = 0;
        parent::__construct([33, 34, 56, 61], $ambiente);
        $this->timestamp = Carbon::now('America/Santiago');
    }

    public function envioDte(Request $request, $ambiente): JsonResponse
    {
        // Leer string como json
        $dte = json_decode(json_encode($request->json()->all()));

        // Set ambiente certificacón
        $this->setAmbiente($ambiente);

        // Primer folio a usar para envio de set de pruebas

        // Comparar cantidad de folios usados con cantidad de folios disponibles

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
        $dteXml = $this->generarEnvioDteXml($documentos, $firma, $folios, $caratula);
        if(is_array($dteXml)){
            return response()->json([
                'message' => "Error al generar el envio de DTEs",
                'errores' => json_decode(json_encode($dteXml)),
            ], 400);
        }

        // Enviar DTE al SII e insertar en base de datos de ser exitoso
        list($envioResponse, $filename) = $this->enviar($caratula['RutEnvia'], $caratula['RutEmisor'], "60803000-K", $dteXml);
        if (!$envioResponse) {
            return response()->json([
                'message' => "Error al enviar el DTE",
                'errores' => Log::read()->msg,
            ], 400);
        }

        if($envioResponse->STATUS != '0') {
            return response()->json([
                'message' => "Error en la respuesta del SII al enviar dte",
                'errores' => $envioResponse,
            ], 400);
        }

        // Guardar en base de datos envio, xml, etc
        $dbresponse = $this->guardarXmlDB($envioResponse, $filename, $caratula, $dte->Documentos[0], $dteXml);
        if (isset($dbresponse['error'])) {
            return response()->json([
                'message' => "Error al guardar el DTE en la base de datos",
                'errores' => $dbresponse['error'],
            ], 400);
        }

        // Enviar DTE a receptor
        return $this->enviarDteReceptor($documentos, $firma, $folios, $caratula);
    }

    private function enviarDteReceptor($documentos, $firma, $folios, $caratula)
    {
        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $dteXml = $this->generarEnvioDteXml($documentos, $firma, $folios, $caratula);
        if(is_array($dteXml)){
            return response()->json([
                'message' => "Error al generar el envio de DTEs",
                'errores' => json_decode(json_encode($dteXml)),
            ], 400);
        }

        // Guardar en Storage
        if (!str_contains($dteXml, '<?xml')) {
            $dteXml = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $dteXml;
        }
        do {
            list($file, $filename) = $this->guardarXML($caratula['RutReceptor']);
        } while (file_exists($file));

        if(!Storage::disk('dtes')->put($caratula['RutReceptor'].'\\'.$filename, $dteXml)) {
            Log::write(0, 'Error al guardar dte en Storage');
            return response()->json([
                'message' => "Error al guardar el DTE en el Storage",
            ], 400);
        }

        // Guardar en base de datos envio, xml, etc
        // trackid 0 por que es un envio a receptor
        $envioResponse = ['trackid' => 0];
        $envioResponse = json_decode(json_encode($envioResponse));
        $dbresponse = $this->guardarXmlDB($envioResponse, $filename, $caratula, $documentos[0], $dteXml);
        if (isset($dbresponse['error'])) {
            return response()->json([
                'message' => "Error al guardar el DTE en la base de datos",
                'errores' => $dbresponse['error'],
            ], 400);
        }

        return response()->json([
            'message' => "DTE enviado correctamente",
            'response' => [
                "EnvioFactura" => $envioResponse,
            ],
        ], 200);
    }

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
            'RutReceptor'       => $body->rut_receptor,
            'DvReceptor'        => $body->dv_receptor,
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

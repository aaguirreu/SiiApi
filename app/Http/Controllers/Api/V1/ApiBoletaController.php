<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiBoletaController extends BoletaController
{
    public function __construct()
    {
        $ambiente = 0;
        $url = 'https://apicert.sii.cl/recursos/v1/boleta.electronica.envio/';
        if ($ambiente == 1)
            $url = 'https://api.sii.cl/recursos/v1/boleta.electronica.envio/';
        parent::__construct([39, 41], $url, $ambiente);
        $this->timestamp = Carbon::now('America/Santiago');
    }

    public function boletaElectronica(Request $request): JsonResponse
    {
        // Leer string como json
        $dte = json_decode(json_encode($request->json()->all()));

        // setear timestamp
        $this->timestamp = Carbon::now('America/Santiago');

        // Renovar token si es necesario
        $this->isToken();

        // Primer folio a usar para envio de set de pruebas

        // Comparar cantidad de folios usados con cantidad de folios disponibles

        // Obtiene los folios con la cantidad de folios usados desde la base de datos
        self::$folios_inicial = $this->obtenerFolios($dte);

        // Variable auxiliar para guardar el folio inicial

        // Parseo de boletas según modelo libreDTE
        $boletas = $this->parseDte($dte);

        // Si hay errores en el parseo, retornarlos
        if (isset($boletas[0]['error'])) {
            return response()->json([
                'message' => "Error al parsear la boleta electrónica",
                'errores' => json_decode(json_encode($boletas))
            ], 400);
        }

        // Objetos de Firma y Folios
        $Firma = $this->obtenerFirma();

        $caratula = $this->obtenerCaratula($dte, $boletas, $Firma);
        $Folios = $this->obtenerFoliosCaf();
        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $EnvioDTExml = $this->generarEnvioDteXml($boletas, $Firma, $Folios, $caratula);
        if (gettype($EnvioDTExml) == 'array') {
            return response()->json([
                'message' => "Error al generar el envio de DTEs",
                'errores' => json_decode(json_encode($EnvioDTExml)),
            ], 400);
        }

        // Enviar DTE e insertar en base de datos de ser exitoso
        $RutEnvia = $Firma->getID(); // RUT autorizado para enviar DTEs
        $RutEmisor = $boletas[0]['Encabezado']['Emisor']['RUTEmisor']; // RUT del emisor del DTE
        $dteresponse = $this->enviar($RutEnvia, $RutEmisor, $EnvioDTExml);

        // Actualizar folios en la base de datos
        $this->actualizarFolios();
        return response()->json([
            'message' => "Boleta electronica y rcof enviado correctamente",
            'response' => [
                "EnvioBoleta" => json_decode($dteresponse),
                //'EnvioRcof' => json_decode(json_encode(["trackid" => $rcofreponse]))
            ],
        ], 200);
    }

    public function estadoDteEnviado(Request $request): JsonResponse
    {
        // Renovar token si es necesario
        $this->isToken();

        // Leer string como json
        $rbody = json_encode($request->json()->all());

        // Transformar a json
        $body = json_decode($rbody);

        // consultar estado dte
        $rut = $body->rut;
        $dv = $body->dv;
        $trackID = $body->trackID;
        $destino = $rut . '-' . $dv . '-' . $trackID;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.sii.cl/recursos/v1/boleta.electronica.envio/' . $destino,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Cookie: TOKEN=' . json_decode(file_get_contents(base_path('config.json')))->token,
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return response()->json([
            'response' => json_decode($response),
        ], 200);
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
        $required = $rut . '-' . $dv . '-' . $tipo . '-' . $folio;
        $opcionales = '?rut_receptor=' . $rut_receptor . '&dv_receptor=' . $dv_receptor . '&monto=' . $monto . '&fechaEmision=' . $fechaEmision;
        $url = 'https://api.sii.cl/recursos/v1/boleta.electronica/' . $required . '/estado' . $opcionales;
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
                'Cookie: TOKEN=' . json_decode(file_get_contents(base_path('config.json')))->token,
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return response()->json([
            'response' => json_decode($response),
        ], 200);
    }

    // Se debe enviar el xml del EnvioBoleta que se desea realizar el resumen rcof.
    public function enviarRcofOnly(Request $request): JsonResponse
    {
        $dte_filename = $request->route('dte_filename');
        if (!DB::table('envio_dte')->where('xml_filename', '=', $dte_filename)->exists()) {
            return response()->json([
                'message' => 'No existe el EnvioDte con ese nombre',
            ], 400);
        }

        // setear timestamp
        $this->timestamp = Carbon::now('America/Santiago');

        // Renovar token si es necesario
        $this->isToken();

        // Obtener resumen de consumo de folios
        $EnvioBoletaxml = $request->getContent();
        $ConsumoFolio = $this->generarRCOF($EnvioBoletaxml);

        // Enviar rcof
        $response = $this->enviarRCOF($ConsumoFolio, $dte_filename);
        if ($response != false) {
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

    public function subirCaf(Request $request): JsonResponse
    {
        return $this->uploadCaf($request);
    }

    public function forzarSubirCaf(Request $request): JsonResponse
    {
        return $this->uploadCaf($request, true);
    }

}

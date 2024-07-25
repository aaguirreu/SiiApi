<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use phpseclib\Net\SCP;
use phpseclib\Net\SFTP;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii\EnvioDte;

class ApiBoletaController extends BoletaController
{
    public function __construct()
    {
        parent::__construct([39, 41]);
        $this->timestamp = Carbon::now('America/Santiago');
    }

    public function estadoEnvioDte(Request $request, $ambiente): JsonResponse
    {
        // consultar estado dte
        $rut = $request->rut;
        $dv = $request->dv;
        $trackID = $request->track_id;
        $destino = $rut . '-' . $dv . '-' . $trackID;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => self::$url_api.".envio/" . $destino,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Cookie: TOKEN=' . self::$token_api,
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        if (!$response || !json_decode($response)) {
            return response()->json([
                'message' => 'Error al consultar estado DTE. Verifique rut, dv y trackd_id',
                'error' => $response,
            ], 400);
        }

        return response()->json([
            'response' => json_decode($response),
        ], 200);
    }

    public function estadoDocumento(Request $request, $ambiente): JsonResponse
    {
        // Consulta estado dte
        $rut = $request->rut;
        $dv = $request->dv;
        $tipo = $request->tipo;
        $folio = $request->folio;
        $rut_receptor = $request->rut_receptor;
        $dv_receptor = $request->dv_receptor;
        $monto = $request->monto;
        $fechaEmision = $request->fecha_emision;
        $required = $rut . '-' . $dv . '-' . $tipo . '-' . $folio;
        $opcionales = '?rut_receptor=' . $rut_receptor . '&dv_receptor=' . $dv_receptor . '&monto=' . $monto . '&fechaEmision=' . $fechaEmision;
        $url = self::$url_api. "/" . $required . '/estado' . $opcionales;
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
                'Cookie: TOKEN=' . self::$token_api,
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        if (!$response || !json_decode($response)) {
            return response()->json([
                'message' => 'Error al consultar estado DTE. Verifique rut, dv y trackd_id',
                'error' => $response,
            ], 400);
        }

        return response()->json([
            'response' => json_decode($response),
        ], 200);
    }
}

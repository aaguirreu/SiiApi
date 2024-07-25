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
     * @param string $ambiente
     * @return bool|string
     * Estado de envío de DTE
     */
    public function estadoEnvioDte(Request $request, string $ambiente)
    {
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

}

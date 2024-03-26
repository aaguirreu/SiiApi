<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PasarelaController;
use App\Jobs\ProcessEnvioDteSii;
use App\Models\Envio;
use Carbon\Carbon;
use Illuminate\Http\Request;
use sasco\LibreDTE\Sii\Folios;
use SimpleXMLElement;

/**
 *
 * Método que utiliza la api como pasarela
 * Es decir, sin utilizar base de datos (o casi)
 * Se utiliza la DB solo para almacenar trackid.
 */
class ApiPasarelaController extends PasarelaController
{
    public function __construct()
    {
        parent::__construct([33, 34, 39, 41, 46, 52, 56, 61, 110, 111, 112]);
        $this->timestamp = Carbon::now('America/Santiago');
    }

    /**
     * Genera un DTE
     *
     * @param Request $request
     */
    public function generarDte(Request $request, $ambiente)//: SimpleXMLElement
    {
        // Obtener json
        $dte = $request->json()->all();

        // Set ambiente certificacón
        $this->setAmbiente($ambiente);

        // Extraer los valores de TipoDTE de cada documento
        $tipos_dte = array_map(function($documento) {
            return $documento['Encabezado']['IdDoc']['TipoDTE'];
        }, $dte['Documentos']);

        // Extraer los Caf como Objetos Folios
        $Folios = array_reduce($dte['Cafs'], function ($carry, $caf) {
            $caf = base64_decode($caf);
            $folios = new Folios($caf);
            $carry[$folios->getTipo()] = $folios;
            return $carry;
        }, []);

        // Verificar que los CAFs sean válidos
        foreach ($Folios as $Folio) {
            if (!$Folio->check()) {
                return response()->json([
                    'error' => "Error al leer CAF",
                ], 400);
            }
        }

        // Extraer los valores de TD cada elemento en Cafs
        $tipos_cafs = array_map(function($caf) {
            return $caf->getTipo();
        }, $Folios);

        // Encontrar los tipoDTE que no traen su CAF correspondiente
        $tipos_dte_diff = array_diff($tipos_dte, $tipos_cafs);

        // Si un documento no tiene CAF, retorna error
        foreach ($tipos_dte_diff as $tipo_dte) {
            return response()->json([
                'error' => "No hay coincidencia para TipoDTE: $tipo_dte en los Cafs"
            ], 400);
        }

        // Objetos de Firma y Folios
        $Firma = $this->obtenerFirma();

        // Obtener caratula
        $caratula = $this->obtenerCaratula(json_decode(json_encode($dte)), $dte['Documentos'], $Firma);

        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $envio_dte_xml = $this->generarEnvioDteXml($dte['Documentos'], $Firma, $Folios, $caratula);
        if(is_array($envio_dte_xml)){
            return response()->json([
                'message' => "Error al generar el XML",
                'errores' => json_decode(json_encode($envio_dte_xml)),
            ], 400);
        }

        // Guardar en DB
        $Envio = new Envio();
        $envio_id = $Envio->insertGetId([
            'estado' => null,
            'rut_emisor' => $caratula['RutEmisor'],
            'rut_receptor' => $caratula['RutReceptor'],
            'tipo_dte' => $dte['Documentos'][0]['Encabezado']['IdDoc']['TipoDTE'],
            'folio' => $dte['Documentos'][0]['Encabezado']['IdDoc']['Folio'],
            'track_id' => null,
            'created_at' => $this->timestamp,
            'updated_at' => $this->timestamp,
        ]);

        // Si Documento contiene 39 o 41
        if (in_array(39, $tipos_dte) || in_array(41, $tipos_dte)) {
            $tipo = 'boleta';
        } else {
            $tipo = 'dte';
        }

        $envio_arr = [
            'id' => $envio_id,
            'caratula' => $caratula,
            'xml' => base64_encode($envio_dte_xml),
            'tipo' => $tipo,
            'ambiente' => self::$ambiente,
        ];

        // Dispatch job para enviar a SII de manera asincrónica
        ProcessEnvioDteSii::dispatch($envio_arr);

        return $envio_dte_xml;
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PasarelaController;
use App\Jobs\ProcessEnvioDteSii;
use App\Mail\DteResponse;
use App\Models\Envio;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use sasco\LibreDTE\Log;
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
        //return base64_decode($request->json()->all()['base64']);

        /*return response()->json([
            'xml' => base64_encode($request->json()->get('xml'))
        ], 200);*/


        $validator = Validator::make($request->all(), [
            'Caratula' => 'required',
            'Documentos' => 'required|array',
            'Documentos.*.Encabezado.IdDoc.TipoDTE' => 'required|integer',
            'Documentos.*.Encabezado.IdDoc.Folio' => 'required|integer',
            'Cafs' => 'required|array',
        ], [
            'Caratula.required' => 'Caratula es requerida',
            'Documentos.required' => 'Documentos es requerido',
            'Documentos.*.Encabezado.IdDoc.TipoDTE.required' => 'TipoDTE es requerido',
            'Documentos.*.Encabezado.IdDoc.TipoDTE.integer' => 'TipoDTE debe ser un número entero',
            'Documentos.*.Encabezado.IdDoc.Folio.integer' => 'Folio debe ser un número entero',
            'Documentos.*.Encabezado.IdDoc.Folio.required' => 'Folio es requerido',
            'Cafs.required' => 'Cafs es requerido',
            'Cafs.array' => 'Cafs debe ser un arreglo',
        ]);

        // Si falla la validación, retorna una respuesta Json con el error
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

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
            $folio = new Folios($caf);
            $carry[$folio->getTipo()] = $folio;
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
                'error' => "No hay coincidencia para TipoDTE = $tipo_dte en los CAFs obtenidos"
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
                'error' => "Error al generar el XML",
                'message' => $envio_dte_xml,
            ], 400);
        }

        // Guardar en DB
        $Envio = new Envio();

        // Verificar si ya existe el envío
        $envio = $Envio->where('rut_emisor', '=', $caratula['RutEmisor'])
            ->where('rut_receptor', '=', $caratula['RutReceptor'])
            ->where('tipo_dte', '=', $dte['Documentos'][0]['Encabezado']['IdDoc']['TipoDTE'])
            ->where('folio', '=', self::$ambiente == 0 ? $dte['Documentos'][0]['Encabezado']['IdDoc']['Folio'] : -(int)$dte['Documentos'][0]['Encabezado']['IdDoc']['Folio'])
            ->latest()->first();

        // Si no existe, crear
        if ($envio) {
            $envio_id = $envio->id;
        } else {
            $envio_id = $Envio->insertGetId([
                'estado' => null,
                'rut_emisor' => $caratula['RutEmisor'],
                'rut_receptor' => $caratula['RutReceptor'],
                'tipo_dte' => $dte['Documentos'][0]['Encabezado']['IdDoc']['TipoDTE'],
                // Folio negativo para ambiente certificación
                'folio' => self::$ambiente == 0 ? $dte['Documentos'][0]['Encabezado']['IdDoc']['Folio'] : -(int)$dte['Documentos'][0]['Encabezado']['IdDoc']['Folio'],
                'track_id' => null,
                'created_at' => $this->timestamp,
                'updated_at' => $this->timestamp,
            ]);
        }

        // Si Documento es de tipo 39 o 41 es Boleta
        if (in_array(39, $tipos_dte) || in_array(41, $tipos_dte)) {
            $tipo = 'boleta';
        } else {
            $tipo = 'dte';
        }

        $base64_xml = base64_encode($envio_dte_xml);
        $envio_arr = [
            'id' => $envio_id,
            'caratula' => $caratula,
            'xml' => $base64_xml,
            'tipo' => $tipo,
            'ambiente' => self::$ambiente == 0 ? 'produccion' : 'certificacion',
        ];

        // Dispatch job para enviar a SII de manera asincrónica
        ProcessEnvioDteSii::dispatch($envio_arr);

        return response()->json([
            'dte_xml' => $base64_xml,
        ], 200);
    }

    public function estadoEnvioDte(Request $request, $ambiente)
    {
        $validator = Validator::make($request->all(), [
            'rut_emisor' => 'required|string',
            'dv_emisor' => 'required|string',
            'rut_receptor' => 'required|string',
            'dv_receptor' => 'required|string',
            'tipo_dte' => 'required|integer',
            'folio' => 'required|integer',
        ], [
            'rut_emisor.required' => 'Rut Emisor es requerido',
            'dv_emisor.required' => 'Dv Emisor es requerido',
            'rut_receptor.required' => 'Rut Receptor es requerido',
            'dv_receptor.required' => 'Dv Receptor es requerido',
            'tipo_dte.required' => 'Tipo DTE es requerido',
            'folio.required' => 'Folio es requerido',
        ]);

        // Si falla la validación, retorna una respuesta Json con el error
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        // Set ambiente certificacón
        $this->setAmbiente($ambiente);

        $Envio = new Envio();
        /* @var Model $envio */
        $envio = $Envio->where('rut_emisor', '=', "{$request['rut_emisor']}-{$request['dv_emisor']}")
            ->where('rut_receptor','=',  "{$request['rut_receptor']}-{$request['dv_receptor']}")
            ->where('tipo_dte','=',  $request['tipo_dte'])
            ->where('folio','=',  self::$ambiente == 0 ? $request['folio'] : -$request['folio'])
            ->latest()->first();
        if (!$envio)
            return response()->json([
                'error' => "No se encontró el envío",
            ], 404);

        // Si es boleta o DTE
        if($request->tipo_dte == 39 || $request->tipo_dte == 41){
            $controller = new ApiBoletaController();
            $controller->setAmbiente($ambiente);
        } else {
            $controller = new ApiFacturaController();
            $controller->setAmbiente($ambiente);
        }

        $request['rut'] = $request['rut_emisor'];
        $request['dv'] = $request['dv_emisor'];
        $request['track_id'] = $envio->track_id;

        return $controller->estadoEnvioDte($request, $ambiente);
    }
}

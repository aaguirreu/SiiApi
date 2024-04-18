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
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii\Folios;
use SimpleXMLElement;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Support\MessageCollection;

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
        /*return response()->json([
            'xml' => base64_encode($request->getContent())
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
            ->where('folio', '=', $dte['Documentos'][0]['Encabezado']['IdDoc']['Folio'])
            ->where('ambiente', '=', self::$ambiente)
            ->latest()->first();

        // Si no existe, crear
        if ($envio) {
            $envio_id = $envio->id;
        } else {
            try {
                $envio_id = $Envio->insertGetId([
                    'estado' => null,
                    'rut_emisor' => $caratula['RutEmisor'],
                    'rut_receptor' => $caratula['RutReceptor'],
                    'tipo_dte' => $dte['Documentos'][0]['Encabezado']['IdDoc']['TipoDTE'],
                    // Folio negativo para ambiente certificación
                    'folio' => $dte['Documentos'][0]['Encabezado']['IdDoc']['Folio'],
                    'track_id' => null,
                    'ambiente' => self::$ambiente,
                    'created_at' => $this->timestamp,
                    'updated_at' => $this->timestamp,
                ]);
                $envio = $Envio->find($envio_id);
            } catch (Exception $e) {
                return response()->json([
                    'error' => "Error al guardar en la base de datos",
                    'message' => $e->getMessage(),
                ], 400);
            }
        }

        $base64_xml = base64_encode($envio_dte_xml);
        $envio_arr = [
            'caratula' => $caratula,
            'xml' => $base64_xml,
        ];

        // Dispatch job para enviar a SII de manera asincrónica
        ProcessEnvioDteSii::dispatch($envio, $envio_arr);

        return response()->json([
            'dte_xml' => $base64_xml,
        ], 200);
    }

    public function estadoEnvio(Request $request, $ambiente)
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

        // Si es boleta o DTE
        if($request->tipo_dte == 39 || $request->tipo_dte == 41) {
            $controller = new ApiBoletaController();
            $controller->setAmbiente($ambiente);
        } else {
            $controller = new ApiFacturaController();
            $controller->setAmbiente($ambiente);
        }

        $Envio = new Envio();
        /* @var Model $envio */
        $envio = $Envio->where('rut_emisor', '=', "{$request['rut_emisor']}-{$request['dv_emisor']}")
            ->where('rut_receptor','=',  "{$request['rut_receptor']}-{$request['dv_receptor']}")
            ->where('tipo_dte','=',  $request['tipo_dte'])
            ->where('folio','=',  $request['folio'])
            ->where('ambiente','=',  self::$ambiente)
            ->latest()->first();
        if (!$envio)
            return response()->json([
                'error' => "No se encontró el envío",
            ], 404);

        $request['rut'] = $request['rut_emisor'];
        $request['dv'] = $request['dv_emisor'];
        // Verificar si existe trackid
        if (!$envio->track_id) {
            // Si hubo un error en el envío mostrar glosa
            if ($envio->estado == 'error') {
                return response()->json([
                    'error' => $envio->glosa,
                ], 200);
            } else {
                return response()->json([
                    'error' => "Pendiente de envío al SII",
                ], 404);
            }
        }
        $request['track_id'] = $envio->track_id;

        return $controller->estadoEnvioDte($request, $ambiente);
    }

    public function estadoDocumento(Request $request, $ambiente)
    {
        $validator = Validator::make($request->all(), [
            'rut' => 'required|string',
            'dv' => 'required|string',
            'tipo' => 'required|integer',
            'folio' => 'required|integer',
            'rut_receptor' => 'required|string',
            'dv_receptor' => 'required|string',
            'monto' => 'required|numeric',
            'fecha_emision' => 'required|date_format:d-m-Y',
        ], [
            'rut.required' => 'Rut Emisor es requerido',
            'dv.required' => 'Dv Emisor es requerido',
            'tipo.required' => 'Tipo DTE es requerido',
            'folio.required' => 'Folio es requerido',
            'rut_receptor.required' => 'Rut Receptor es requerido',
            'dv_receptor.required' => 'Dv Receptor es requerido',
            'monto.required' => 'Monto es requerido',
            'fecha_emision.required' => 'Fecha de emisión es requerida',
        ]);

        // Si falla la validación, retorna una respuesta Json con el error
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        // Si es boleta o DTE
        if($request->tipo == 39 || $request->tipo == 41) {
            $controller = new ApiBoletaController();
            $controller->setAmbiente($ambiente);
        } else {
            $controller = new ApiFacturaController();
            $controller->setAmbiente($ambiente);
        }

        return $controller->estadoDocumento($request, $ambiente);

    }

    /**
     * Importa los DTEs desde el correo
     */
    public function importarDtesCorreo(Request $request): JsonResponse
    {
        $body = $request->only(['mail', 'password', 'host', 'port', 'protocol', 'folder']);
        $cm = new ClientManager(base_path().'/config/imap.php');
        $client = $cm->make([
            'host'          => $body['host'],
            'port'          => $body['port'],
            'encryption'    => $body['encryption'] ?? 'ssl',
            'validate_cert' => $body['validate_cert'] ?? true,
            'username'      => $body['mail'],
            'password'      => $body['password'],
            'protocol'      => $body['protocol'] ?? 'imap'
        ]);

        try {
            $client->connect();
        } catch (Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }

        // Obtener todos los correos no leídos en el folder INBOX
        /* @var Folder $folder */
        $folder = $client->getFolder($body['folder'] ?? 'INBOX');
        $unseenMessages = $folder->query()->unseen()->get();

        // Procesar cada mensaje no leído
        $correos = [];
        /* @var Message $message */
        foreach ($unseenMessages as $message) {
            if ($message->hasAttachments()) {
                // Verificar si adjunto es un DTE
                list($xml, $pdf) = $this->procesarAttachments($message);
                if (!isset($xml[0])) {
                    continue;
                }

                // Quitar firmas a adjuntos
                $attachments = $this->quitarFirmas($xml);

                $correos[] = [
                    "uid" => $message->uid,
                    "from" => $message->from[0]->mail,
                    "subject" => mb_decode_mimeheader($message->subject),
                    "date" => $message->date->get(),
                    "xmlb64" => base64_encode($xml[0]->getContent()),
                    "pdfb64" => isset($pdf[0]) ? base64_encode($pdf[0]->getContent()) : null,
                    "content" => $attachments[0]['content'],
                ];
                $message->setFlag('Seen');
            }
        }

        # Revisar error
        //$client->disconnect();
        //$cm->disconnect();

        return response()->json(json_decode(json_encode($correos, true)), 200);
    }

    protected function procesarAttachments($message): array
    {
        $attachments_arr = [];
        $pdf_arr = [];
        /* @var  MessageCollection $Attachments*/
        $Attachments = $message->getAttachments();
        if (!$Attachments->isEmpty()) {
            /**
             * Obtener el contenido del adjunto
             *
             * @var Attachment $Attachment
             * @var string $content
             */
            foreach ($Attachments as $Attachment) {
                // Verificar si el adjunto es un xml
                if(str_ends_with($Attachment->getName(), '.xml')) {
                    // Ver si el xmles un dte o una respuesta a un dte
                    $Xml = new SimpleXMLElement($Attachment->getContent());
                    $tipoXml = $Xml[0]->getName();
                    if($tipoXml == 'EnvioDTE') {
                        $attachments_arr[] = $Attachment;
                    }
                } elseif (str_ends_with($Attachment->getName(), '.pdf')) {
                    $pdf_arr[] = $Attachment;
                }
            }
        }
        return [$attachments_arr, $pdf_arr];
    }

    protected function quitarFirmas($attachments): array
    {
        $attachments_arr = [];
        /**
         * Obtener el contenido del adjunto
         *
         * @var Attachment $Attachment
         * @var string $content
         */
        foreach ($attachments as $Attachment) {
            $Xml = new SimpleXMLElement($Attachment->getContent());

            // Eliminar las firmas del XML si existen
            if (isset($Xml->Signature)) {
                unset($Xml->Signature);
            }

            if (isset($Xml->SetDTE->Signature)) {
                unset($Xml->SetDTE->Signature);
            }

            foreach ($Xml->SetDTE->DTE as $Dte) {
                if (isset($Dte->Signature)) {
                    unset($Dte->Signature);
                }
                foreach ($Dte->Documento as $Documento) {
                    if (isset($Documento->TED)) {
                        unset($Documento->TED);
                    }
                }
            }

            $attachments_arr[] = [
                "filename" => $Attachment->getName(),
                "content" =>  json_decode(json_encode($Xml), true),
            ];
        }
        return $attachments_arr;
    }
}

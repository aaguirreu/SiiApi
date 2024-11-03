<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\PasarelaController;
use App\Jobs\ProcessEnvioDteReceptor;
use App\Jobs\ProcessEnvioDteSii;
use App\Mail\DteResponse;
use App\Models\Envio;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\Sii\ConsumoFolio;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\Sii\Folios;
use SimpleXMLElement;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

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
     * Envío Async (Jobs)
     * @param Request $request
     */
    public function generarDte(Request $request, $ambiente): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'Caratula' => 'required',
            'Documentos' => 'required|array',
            'Documentos.*.Encabezado.IdDoc.TipoDTE' => 'required|integer',
            'Documentos.*.Encabezado.IdDoc.Folio' => 'required|integer',
            'Cafs' => 'required|array',
            'firmab64' => 'required|string',
            'pswb64' => 'required|string',
        ], [
            'Caratula.required' => 'Caratula es requerida',
            'Documentos.required' => 'Documentos es requerido',
            'Documentos.*.Encabezado.IdDoc.TipoDTE.required' => 'TipoDTE es requerido',
            'Documentos.*.Encabezado.IdDoc.TipoDTE.integer' => 'TipoDTE debe ser un número entero',
            'Documentos.*.Encabezado.IdDoc.Folio.integer' => 'Folio debe ser un número entero',
            'Documentos.*.Encabezado.IdDoc.Folio.required' => 'Folio es requerido',
            'Cafs.required' => 'Cafs es requerido',
            'Cafs.array' => 'Cafs debe ser un arreglo',
            'firmab64.required' => 'firmab64 es requerida',
            'pswb64.required' => 'pswb64 es requerida',
        ]);

        // Si falla la validación, retorna una respuesta Json con el error
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        if($request->correo_receptor) {
            $validator = Validator::make($request->all(), [
                'correo_receptor' => 'required|email',
                'mail' => 'required|email',
                'password' => 'required|string',
                'host' => 'required|string',
                'port' => 'required|integer',
            ], [
                'correo_receptor' => 'correo_receptor debe ser un correo válido',
                'mail.email' => 'mail debe ser un correo válido',
                'mail.required' => 'mail es requerido',
                'password.required' => 'password es requerida',
                'host.required' => 'host es requerido',
                'port.required' => 'port es requerido',
            ]);

            // Si falla la validación, retorna una respuesta Json con el error
            if ($validator->fails()) {
                $error = $validator->errors()->all();
                $error[] = 'Rut receptor detectado. Ingrese credenciales de correo emisor.';
                return response()->json([
                    'error' => $error,
                ], 400);
            }
        }

        // Obtener json
        $dte = $request->json()->all();

        // Obtener firma
        list($cert_path, $Firma) = $this->importarFirma($tmp_dir, base64_decode($request->firmab64), base64_decode($request->pswb64));
        if (is_array($Firma)) {
            return response()->json([
                'error' => $Firma['error'],
            ], 400);
        }

        // verificar Token SII
        $rut_envia = $Firma->getID();
        //$this->isToken($rut_envia, $Firma);

        // Set ambiente
        //$this->setAmbiente($ambiente, $rut_envia);
        if ($ambiente == "certificacion" || $ambiente == 1) {
            Sii::setAmbiente(1);
            self::$ambiente = 1;
        } else if ($ambiente == "produccion" || $ambiente == 0) {
            Sii::setAmbiente(0);
            self::$ambiente = 0;
        }

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
            /** @var Folios $Folio */
            if (!$Folio->check()) {
                return response()->json([
                    'error' => ["Error al leer CAF", Log::read()],
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

        // Obtener caratula
        $caratula = $this->obtenerCaratula(json_decode(json_encode($dte)), $dte['Documentos'], $Firma);

        // generar cada DTE, timbrar, firmar y agregar al envío
        $envio_dte_xml = $this->generarEnvioDteXml($dte['Documentos'], $Firma, $Folios, $caratula);
        if(is_array($envio_dte_xml)) {
            return response()->json([
                'error' => "Error al generar el XML",
                'message' => $envio_dte_xml,
            ], 400);
        }

        // Guardar en DB y verificar si ya existe el envío
        $Envio = new Envio();
        $envio = $Envio->where('rut_emisor', '=', $caratula['RutEmisor'])
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
                    'tipo_dte' => $dte['Documentos'][0]['Encabezado']['IdDoc']['TipoDTE'],
                    'folio' => $dte['Documentos'][0]['Encabezado']['IdDoc']['Folio'],
                    'track_id' => null,
                    'ambiente' => self::$ambiente,
                    'created_at' => $this->timestamp,
                    'updated_at' => $this->timestamp,
                ]);
                $envio = $Envio->find($envio_id)                                                                                                                                                                                                                                                                                                                                                                                                                                           ;
            } catch (Exception $e) {
                return response()->json([
                    'error' => "Error al guardar en la base de datos",
                    'message' => $e->getMessage(),
                ], 400);
            }
        }

        // Generar PDF
        $base64_xml = base64_encode($envio_dte_xml);

        // Asigna true si es 'H' False caso contrario
        $continuo = $request->formato_impresion == 'T';
        if(in_array($request->formato_impresion, array(0, 57, 70, 75, 77, 80, 110)))
            $continuo = $request->formato_impresion;

        // Llama a la función xmlPdf con los argumentos claros
        $pdfb64_arr = $this->xmlPdf($envio_dte_xml, $continuo, $request->logob64, $request->observaciones, $request->cedible, $request->copia_cedible, $request->footer, $request->tickets);

        // Si hubo un error retornar error
        if (!$pdfb64_arr) {
            Log::write("No se pudo generar PDF");
            return response()->json([
                'error' => Log::read(),
            ], 400);
        }

        $envio_arr = [
            'caratula' => $caratula,
            'xml' => $base64_xml,
            'request' => $dte
        ];

        // Agregar todos los datos si existe receptor
        if ($request->correo_receptor) {
            $envio_arr['request']['pdfb64'] = $pdfb64_arr;

            // Dispatch jobs en cadena para enviar a SII de manera asincrónica
            Bus::chain([
                new ProcessEnvioDteSii($envio, $envio_arr),
                new ProcessEnvioDteReceptor($envio, $envio_arr['request'])
            ])->dispatch();
        } else {
            // Dispatch job para enviar a SII de manera asincrónica
            ProcessEnvioDteSii::dispatch($envio, $envio_arr);
        }

        return response()->json([
            'dte_xml' => $base64_xml,
            'pdfb64' => $pdfb64_arr,
        ], 200);
    }

    /**
     * Consulta estado de envío de un DTE
     * @param Request $request
     */
    public function estadoEnvio(Request $request, $ambiente) : JsonResponse|string
    {
        $validator = Validator::make($request->all(), [
            'rut_emisor' => 'required|string',
            'dv_emisor' => 'required|string',
            'tipo_dte' => 'required|integer',
            'folio' => 'required|integer',
            'firmab64' => 'required|string',
            'pswb64' => 'required|string',
        ], [
            'rut_emisor.required' => 'Rut Emisor es requerido',
            'dv_emisor.required' => 'Dv Emisor es requerido',
            'tipo_dte.required' => 'Tipo DTE es requerido',
            'folio.required' => 'Folio es requerido',
            'firmab64.required' => 'firmab64 es requerida',
            'pswb64.required' => 'pswb64 es requerida',
        ]);
        // Si falla la validación, retorna una respuesta Json con el error
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        // Obtener firma
        list($cert_path, $Firma) = $this->importarFirma($tmp_dir, base64_decode($request->firmab64), base64_decode($request->pswb64));
        if (is_array($Firma)) {
            return response()->json([
                'error' => $Firma['error'],
            ], 400);
        }

        // verificar Token SII
        $rut_envia = $Firma->getID();
        try {
            $this->isToken($rut_envia, $Firma);
        } catch (Exception $e) {
            return response()->json([
                'error' => "No se pudo obtener Token SII. ". $e->getMessage(),
            ], 500);
        }

        // Si es boleta o DTE
        if($request->tipo_dte == 39 || $request->tipo_dte == 41) {
            $controller = new ApiBoletaController();
            $controller->setAmbiente($ambiente, $rut_envia);
            if (!$controller::$token_api || $controller::$token_api == '')
                return response()->json([
                    'error' => "No existe Token BE",
                ], 500);
        } else {
            $controller = new ApiFacturaController();
            $controller->setAmbiente($ambiente, $rut_envia);
            if (!$controller::$token || $controller::$token == '')
                return response()->json([
                    'error' => "No existe Token",
                ], 500);
        }

        // Si existe track id en body, consultar inmediatamente
        if(isset($request->track_id)) {
            $request['rut'] = $request['rut_emisor'];
            $request['dv'] = $request['dv_emisor'];
            $request['track_id'] = $request->track_id;
            return $controller->estadoEnvioDte($request, $ambiente);
        }

        $Envio = new Envio();
        /* @var Model $envio */
        $envio = $Envio->where('rut_emisor', '=', "{$request['rut_emisor']}-{$request['dv_emisor']}")
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

    /**
     * Consulta estado de un DTE (Documento)
     * @param Request $request
     */
    public function estadoDocumento(Request $request, $ambiente) : JsonResponse|string
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
            'firmab64' => 'required|string',
            'pswb64' => 'required|string',
        ], [
            'rut.required' => 'Rut Emisor es requerido',
            'dv.required' => 'Dv Emisor es requerido',
            'tipo.required' => 'Tipo DTE es requerido',
            'folio.required' => 'Folio es requerido',
            'rut_receptor.required' => 'Rut Receptor es requerido',
            'dv_receptor.required' => 'Dv Receptor es requerido',
            'monto.required' => 'Monto es requerido',
            'fecha_emision.required' => 'Fecha de emisión es requerida',
            'firmab64.required' => 'firmab64 es requerida',
            'pswb64.required' => 'pswb64 es requerida',
        ]);

        // Si falla la validación, retorna una respuesta Json con el error
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        // Obtener firma
        list($cert_path, $Firma) = $this->importarFirma($tmp_dir, base64_decode($request->firmab64), base64_decode($request->pswb64));
        if (is_array($Firma)) {
            return response()->json([
                'error' => $Firma['error'],
            ], 400);
        }

        // verificar Token SII
        $rut_envia = $Firma->getID();
        try {
            $this->isToken($rut_envia, $Firma);
        } catch (Exception $e) {
            return response()->json([
                'error' => "No se pudo obtener Token SII. ". $e->getMessage(),
            ], 500);
        }

        // Si es boleta o DTE
        if($request->tipo == 39 || $request->tipo == 41) {
            $controller = new ApiBoletaController();
            $controller->setAmbiente($ambiente, $rut_envia);
            if (!$controller::$token_api || $controller::$token_api == '')
                return response()->json([
                    'error' => "No existe Token BE",
                ], 500);
        } else {
            $controller = new ApiFacturaController();
            $controller->setAmbiente($ambiente, $rut_envia);
            if (!$controller::$token || $controller::$token == '')
                return response()->json([
                    'error' => "No existe Token",
                ], 500);
        }

        return $controller->estadoDocumento($request, $ambiente);

    }

    /**
     * Importa los DTEs desde el correo
     * @param Request $request
     */
    public function importarDtesCorreo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'mail' => 'required|email',
            'password' => 'required|string',
            'host' => 'required|string',
            'port' => 'required|integer',
        ], [
            'mail.required' => 'mail es requerido',
            'password.required' => 'password es requerida',
            'host.required' => 'host es requerido',
            'port.required' => 'port es requerido',
        ]);

        // Si falla la validación, retorna una respuesta Json con el error
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        $body = $request->only(['mail', 'password', 'host', 'port', 'protocol', 'folder']);
        $cm = new ClientManager(base_path().'/config/imap.php');
        $client = $cm->make([
            'host'          => $body['host'],
            'port'          => $body['port'],
            'encryption'    => $body['encryption'] ?? 'ssl',
            'validate_cert' => $body['validate_cert'] ?? true,
            'username'      => $body['mail'],
            'password'      => base64_decode($body['password']),
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

        // Crear carpeta dte_IN_procesados si no existe
        $procesados_folder = $client->getFolder('dte_IN_procesados');
        if ($procesados_folder == null)
            $procesados_folder = $client->createFolder("$folder->name/dte_IN_procesados", true);

        // Procesar cada mensaje no leído
        $correos = [];
        /* @var Message $message
         * @var array $reverse
         */
        foreach ($unseenMessages as $message) {
            if ($message->hasAttachments()) {
                // Verificar si adjunto es un DTE
                list($dte_arr, $pdf_arr) = $this->procesarAttachments($message);
                if (!isset($dte_arr[0])) {
                    continue;
                }
                // Solo se retornará el pdf si existe 1 xml y 1 pdf en el correo
                if (!(count($dte_arr) == 1 && count($pdf_arr) == 1)) {
                    $pdf_arr[0] = null;
                }

                $attachments = $this->quitarFirmas($dte_arr);

                // Si xml tiene más de un DTE, dividir en varios
                /**
                 * @var Attachment $dte
                 */
                foreach ($dte_arr as $key => $dte) {
                    if (isset($pdf_arr[0])){
                        $pdfb64 = base64_encode($pdf_arr[0]->getContent());
                    } else {
                        $pdfb64_arr = $this->xmlPdf($dte->getContent());
                        $pdfb64 = array_shift($pdfb64_arr);
                        $pdfb64 = str_replace(array("\r", "\n"), '', $pdfb64);
                    }
                    // Quitar firmas a adjuntos
                    $correos[] = [
                        "uid" => $message->uid,
                        "from" => $message->from[0]->mail,
                        "subject" => mb_decode_mimeheader($message->subject),
                        "date" => $message->date->get(),
                        "xmlb64" => base64_encode($dte->getContent()),
                        //"pdfb64" => isset($pdf_arr[0]) ? base64_encode($pdf_arr[0]->getContent()) : '',
                        "pdfb64" => $pdfb64,
                        "content" => $attachments[$key]['content'],
                    ];
                    //file_put_contents(base_path()."/pdf_$message->uid.pdf", base64_decode($pdfb64));
                }

                try {
                    // Mover correos a dte_IN_procesados
                    $copy = $message->copy($procesados_folder->path);
                    $copy->setFlag('Seen');
                    $message->delete(false);
                } catch (Exception $e) {
                    //$copy->delete();
                    return response()->json([
                        'error' => $e->getMessage()
                    ], 401);
                }
            }
        }

        // Aplicar cambios en servidor de correos: copy() and delete()
        $client->getConnection()->expunge();

        # Revisar error
        //$client->disconnect();
        //$cm->disconnect();
        return response()->json(json_decode(json_encode($correos, true)), 200);
    }

    /**
     * Responder a un documento
     * @param Request $request
     */
    public function respuestaDocumento(Request $request, $ambiente): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rut_receptor' => 'required|string',
            'rut_emisor' => 'required|string',
            'estado' => 'required|integer',
            'accion_doc' => 'required|string',
            'tipo_dte' => 'required|integer',
            'folio' => 'required|integer',
            'fecha_emision' => 'required|string',
            'monto_total' => 'required|string',
        ], [
            'rut_receptor.required' => 'rut_receptor es requerido',
            'dte_xml.required' => 'dte_xml es requerido',
            'estado.required' => 'estado es requerido',
            'accion_doc.required' => 'accion_doc es requerida',
            'firmab64.required' => 'firmab64 es requerida',
            'pswb64.required' => 'pswb64 es requerida',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        if($request->correo_receptor) {
            $validator = Validator::make($request->all(), [
                'correo_receptor' => 'required|email',
                'mail' => 'required|email',
                'password' => 'required|string',
                'host' => 'required|string',
                'port' => 'required|integer',
            ], [
                'correo_receptor' => 'correo_receptor debe ser un correo válido',
                'mail.email' => 'mail debe ser un correo válido',
                'mail.required' => 'mail es requerido',
                'password.required' => 'password es requerida',
                'host.required' => 'host es requerido',
                'port.required' => 'port es requerido',
            ]);

            // Si falla la validación, retorna una respuesta Json con el error
            if ($validator->fails()) {
                $error = $validator->errors()->all();
                $error[] = 'Rut receptor detectado. Ingrese credenciales de correo emisor.';
                return response()->json([
                    'error' => $error,
                ], 400);
            }
        }

        // Obtener firma
        list($cert_path, $Firma) = $this->importarFirma($tmp_dir, base64_decode($request->firmab64), base64_decode($request->pswb64));
        if (is_array($Firma)) {
            return response()->json([
                'error' => $Firma['error'],
            ], 400);
        }

        // verificar Token SII
        $rut_envia = $Firma->getID();
        try {
            $this->isToken($rut_envia, $Firma);
        } catch (Exception $e) {
            return response()->json([
                'error' => "No se pudo obtener Token SII. ". $e->getMessage(),
            ], 500);
        }

        // Set ambiente
        $this->setAmbiente($ambiente, $rut_envia);

        $glosa = match ((int)$request->estado) {
            0 => ".",
            2, 1 => ". $request->glosa",
            default => false,
        };

        if (!$glosa) {
            return response()->json([
                'error' => "estado no válido",
            ], 400);
        }

        // Envío de respuesta de documento a SII
        list($rut_emisor, $dv_emisor) = explode('-', str_replace('.', '', $request->rut_receptor));
        try {
            $respuesta_doc = new \sasco\LibreDTE\Sii\RegistroCompraVenta($Firma);
        } catch (Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
        $response = $respuesta_doc->ingresarAceptacionReclamoDoc($rut_emisor, $dv_emisor, $request->tipo_dte, $request->folio, $request->accion_doc);
        if($response['codigo'] != 0) {
            return response()->json([
                'codigo' => $response['codigo'],
                'error' => $response['glosa'],
            ], 400);
        }

        // Envío de respuesta de documento a emisor
        // Obtener respuesta de documento
        if($request->correo_receptor) {
            $xml_respuesta = $this->generarRespuestaDocumento((int)$request->estado, $glosa, $request, $Firma);
            if (!$xml_respuesta) {
                return response()->json([
                    'error' => Log::read()->msg,
                ], 400);
            }

            // Enviar respuesta por correo
            $respuesta = [
                'filename' => "RespuestaDTE_{$request->rut_receptor}_T{$request->tipo_dte}F{$request->folio}.xml",
                'data' => $xml_respuesta
            ];

            // Enviar respuesta por correo
            try {
                // Configurar la conexión SMTP
                Config::set('mail.mailers.smtp.host', $request['host']);
                Config::set('mail.mailers.smtp.port', $request['port']);
                Config::set('mail.mailers.smtp.username', $request['mail']);
                Config::set('mail.mailers.smtp.password', base64_decode($request['password']));
                Config::set('mail.from.address', $request['mail']);
                Config::set('mail.from.name', env('APP_NAME', 'Logiciel ApiFact'));
                Mail::to($request->correo_receptor)->send(new DteResponse("Receptor: $request->rut_receptor Tipo DTE: $request->tipo_dte Folio: $request->folio", $respuesta));
            } catch (\Exception $e) {
                return response()->json([
                    'error' => $e->getMessage()
                ], 200);
            }
        }

        return response()->json($response, 200);
    }

    public function obtenerCaf(Request $request, $ambiente): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rut' => 'required|string',
            'dv' => 'required|string',
            'tipo' => 'required|integer',
            'cant_doctos' => 'required|integer',
            'firmab64' => 'required|string',
            'pswb64' => 'required|string',
        ], [
            'rut.required' => 'Rut Emisor es requerido',
            'dv.required' => 'Dv Emisor es requerido',
            'tipo.required' => 'Tipo de Folio es requerido',
            'cant_doctos.required' => 'Cantidad de documentos es requerida',
            'firmab64.required' => 'firmab64 es requerida',
            'pswb64.required' => 'pswb64 es requerida',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        // Obtener firma
        list($cert_path, $Firma) = $this->importarFirma($tmp_dir, base64_decode($request->firmab64), base64_decode($request->pswb64));
        if (is_array($Firma)) {
            return response()->json([
                'error' => $Firma['error'],
            ], 400);
        }

        // verificar Token SII
        $rut_envia = $Firma->getID();
        try {
            $this->isToken($rut_envia, $Firma);
        } catch (Exception $e) {
            return response()->json([
                'error' => "No se pudo obtener Token SII. ". $e->getMessage(),
            ], 500);
        }

        // Set ambiente
        $this->setAmbiente($ambiente, $rut_envia);

        $caf_xml = $this->generarNuevoCaf($cert_path, base64_decode($request->pswb64), $request->rut, $request->dv, $request->tipo, $request->cant_doctos);
        if (!$caf_xml) {
            return response()->json([
                'error' => Log::read()->msg,
            ], 400);
        }

        try {
            $xml = new SimpleXMLElement($caf_xml);
        } catch (Exception $e) {
            return response()->json([
                'error' => "No se pudo transformar caf a xml. ".$e->getMessage(),
            ], 400);
        }

        // Calcular fecha de vencimiento a 6 meses de la fecha de autorización
        $fecha_vencimiento = Carbon::parse($xml->CAF->DA->FA[0], 'America/Santiago')->addMonths(6)->format('Y-m-d');

        return response()->json([
            'caf_xml' => base64_encode($caf_xml),
            'fecha_vencimiento' => $fecha_vencimiento,
        ], 200);
    }

    public function resumenVentas(Request $request, $ambiente): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'Caratula' => 'required',
            'Resumen' => 'required',
            'firmab64' => 'required|string',
            'pswb64' => 'required|string',
        ], [
            'Caratula.required' => 'Caratula es requerida',
            'Resumen.required' => 'Resumen es requerido',
            'firmab64.required' => 'firmab64 es requerida',
            'pswb64.required' => 'pswb64 es requerida',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        // Validar Caratula
        $validator = Validator::make($request->toArray()['Caratula'], [
            'RutEmisor' => 'required|string',
            'FchResol' => 'required|string',
            'NroResol' => 'required|integer',
            'FchInicio' => 'required|string',
            'FchFinal' => 'required|string',
            'SecEnvio' => 'required|integer'
        ], [
            'RutEmisor.required' => 'RutEmisor es requerido',
            'FchResol.required' => 'FchResol es requerido',
            'NroResol.required' => 'NroResol es requerido',
            'FchInicio.required' => 'FchInicio es requerido',
            'FchFinal.required' => 'FchFinal es requerido',
            'SecEnvio.required' => 'SecEnvio es requerido',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        // Obtener firma
        list($cert_path, $Firma) = $this->importarFirma($tmp_dir, base64_decode($request->firmab64), base64_decode($request->pswb64));
        if (is_array($Firma)) {
            return response()->json([
                'error' => $Firma['error'],
            ], 400);
        }

        // verificar Token SII
        $rut_envia = $Firma->getID();
        try {
            $this->isToken($rut_envia, $Firma);
        } catch (Exception $e) {
            return response()->json([
                'error' => "No se pudo obtener Token SII. ". $e->getMessage(),
            ], 500);
        }

        // Set ambiente
        $this->setAmbiente($ambiente, $rut_envia);

        $r = $request->toArray();
        $resumen_ventas_diarias = $this->generarResumenVentasDiarias($r['Caratula'], $r['Resumen'], $Firma);
        $consumo_folio = new ConsumoFolio();
        $consumo_folio->setFirma($Firma);
        $consumo_folio->setDocumentos([39, 41]); // [39, 61] si es sólo afecto, [41, 61] si es sólo exento
        $consumo_folio->setCaratula([
            'RutEmisor' => $r["Caratula"]['RutEmisor'],
            'FchResol' => $r["Caratula"]['FchResol'],
            'NroResol' => $r["Caratula"]['NroResol'],
        ]);
        $consumo_folio->loadXML($resumen_ventas_diarias);
        if ($consumo_folio->schemaValidate()) {
            //echo $ConsumoFolio->generar();
            $track_id = $consumo_folio->enviar();
            if (!$track_id) {
                return response()->json([
                    'error' => ["Error al enviar XML", Log::read()->msg],
                ], 400);
            }
            return response()->json([
                'track_id' => $track_id,
                'resumen_xml' => base64_encode($consumo_folio->generar()),
            ], 200);
        }
        return response()->json([
            'error' => ["Error al validar XML", Log::read()->msg],
        ], 400);
    }

    public function generarDteReceptor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'Caratula' => 'required',
            'Documentos' => 'required|array',
            'Documentos.*.Encabezado.IdDoc.TipoDTE' => 'required|integer',
            'Documentos.*.Encabezado.IdDoc.Folio' => 'required|integer',
            'Cafs' => 'required|array',
            'firmab64' => 'required|string',
            'pswb64' => 'required|string',
            'correo_receptor' => 'required|email',
            'mail' => 'required|email',
            'password' => 'required|string',
            'host' => 'required|string',
            'port' => 'required|integer',
        ], [
            'Caratula.required' => 'Caratula es requerida',
            'Documentos.required' => 'Documentos es requerido',
            'Documentos.*.Encabezado.IdDoc.TipoDTE.required' => 'TipoDTE es requerido',
            'Documentos.*.Encabezado.IdDoc.TipoDTE.integer' => 'TipoDTE debe ser un número entero',
            'Documentos.*.Encabezado.IdDoc.Folio.integer' => 'Folio debe ser un número entero',
            'Documentos.*.Encabezado.IdDoc.Folio.required' => 'Folio es requerido',
            'Cafs.required' => 'Cafs es requerido',
            'Cafs.array' => 'Cafs debe ser un arreglo',
            'firmab64.required' => 'firmab64 es requerida',
            'pswb64.required' => 'pswb64 es requerida',
            'correo_receptor' => 'correo_receptor debe ser un correo válido',
            'mail.email' => 'mail debe ser un correo válido',
            'mail.required' => 'mail es requerido',
            'password.required' => 'password es requerida',
            'host.required' => 'host es requerido',
            'port.required' => 'port es requerido',
        ]);

        // Si falla la validación, retorna una respuesta Json con el error
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        // Obtener json
        $dte = $request->json()->all();

        // Obtener firma
        list($cert_path, $Firma) = $this->importarFirma($tmp_dir, base64_decode($request['firmab64']), base64_decode($request['pswb64']));
        if (is_array($Firma)) {
            return response()->json([
                'error' => $Firma['error'],
            ], 400);
        }

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
            /** @var Folios $Folio */
            if (!$Folio->check()) {
                return response()->json([
                    'error' => ["Error al leer CAF", Log::read()],
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

        // Obtener caratula
        $caratula = $this->obtenerCaratula(json_decode(json_encode($dte)), $dte['Documentos'], $Firma);

        // Igualar rut receptor de documento al de caratula
        $caratula['RutReceptor'] = $request['Documentos'][0]['Encabezado']['Receptor']['RUTRecep'] ?? '000-0';

        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $envio_dte_xml = $this->generarEnvioDteXml($dte['Documentos'], $Firma, $Folios, $caratula);
        if(is_array($envio_dte_xml)) {
            return response()->json([
                'error' => "Error al generar el XML",
                'message' => $envio_dte_xml,
            ], 400);
        }

        $envio_arr = [
            'correo_receptor' => $request['correo_receptor'],
            'mail' => $request['mail'],
            'password' => $request['password'],
            'host' => $request['host'],
            'port' => $request['port'],
        ];

        $message = [
            'emisor' => $request['Documentos'][0]['Encabezado']['Emisor']['RznSoc'] ?? '',
            'from' => $request['Documentos'][0]['Encabezado']['Receptor']['RznSocRecep'] ?? '',
            'subject' => "RutEmisor: {$caratula['RutEmisor']} RutReceptor: {$caratula['RutReceptor']}",
            'body' => $request['firma_email'] ?? '',
        ];

        // No enviar copia cedible a correo receptor
        $request->copia_cedible  = false;
        $envio = $this->enviarDteReceptor($envio_dte_xml, $message, $envio_arr, $request->pdfb64, $request->formato_impresion, $request->observaciones, $request->logob64, $request->cedible, $request->copia_cedible, $request->footer, $request->tickets);
        if (!$envio)
            return response()->json([
                'error' => Log::read()->msg,
            ], 400);

        return response()->json(
            $envio
        , 200);
    }

    public function generarPdf(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'xmlb64' => 'required|string',
        ], [
            'xmlb64.required' => 'xml es requerido',
        ]);
        // Si falla la validación, retorna una respuesta Json con el error
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        // Asigna true si es 'H' False caso contrario
        $continuo = $request->formato_impresion == 'T';
        if(in_array($request->formato_impresion, array(0, 57, 70, 75, 77, 80, 110)))
            $continuo = $request->formato_impresion;

        // Llama a la función xmlPdf con los argumentos claros
        $pdfb64_arr = $this->xmlPdf(base64_decode($request->xmlb64), $continuo, $request->logob64, $request->observaciones, $request->cedible, $request->copia_cedible, $request->footer, $request->tickets);

        // Si hubo un error retornar error
        if (!$pdfb64_arr)
            return response()->json([
                'error' => Log::read()
            ], 400);

        // Respuesta exito
        return response()->json([
            'pdfb64' => $pdfb64_arr
        ], 200);
    }

    public function actualizarTokenSii(Request $request): JsonResponse
    {
        return response()->json([
            'exito' => "Tokens del SII actualizados.",
        ], 200);
    }

    public function obtenerRegistroCompraVenta(Request $request, $ambiente)//: JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rut' => 'required|string',
            'dv' => 'required|string',
            //'tipo_dte' => 'required|integer',
            'tipo' => 'required|string|in:detalle,resumen,DETALLE,RESUMEN',
            'operacion' => 'required|string',
            'estado' => "nullable|in:registro,pendiente,no_incluir,reclamado,REGISTRO,PENDIENTE,NO_INCLUIR,RECLAMADO|string",
            'periodo' => 'required|regex:/^[0-9]{6}$/|string',
            'firmab64' => 'required|string',
            'pswb64' => 'required|string',
        ], [
            'rut.required' => 'Rut Emisor es requerido',
            'dv.required' => 'Dv Emisor es requerido',
            //'tipo_dte.required' => 'Tipo de Folio es requerido',
            'tipo.required' => 'Tipo es requerido',
            'tipo.in' => "No existe el tipo '$request->tipo'. Se espera 'resumen' o 'detalle'",
            'operacion.required' => 'Operacion es requerido',
            'estado.in' => "No existe el estado '$request->estado'. Se espera 'registro', 'pendiente', 'no_incluir', 'reclamado'.",
            'estado.required' => 'Estado es requerido',
            'periodo.required' => 'Periodo es requerido',
            'periodo.regex' => "El formato de 'periodo' es incorrecto. Se espera en formato AAAAMM.",
            'firmab64.required' => 'firmab64 es requerida',
            'pswb64.required' => 'pswb64 es requerida',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        if (strtoupper($request->operacion) == 'COMPRA') {
            $validator = Validator::make($request->all(), [
                'estado' => "required|in:registro,pendiente,no_incluir,reclamado,REGISTRO,PENDIENTE,NO_INCLUIR,RECLAMADO|string",
            ], [
                'estado.required' => "Estado es requerido para operación 'compra'. Se espera 'registro', 'pendiente', 'no_incluir', 'reclamado'.",
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'error' => $validator->errors()->all(),
                ], 400);
            }
        } else if (strtoupper($request->operacion) == 'VENTA') {
            $validator = Validator::make($request->all(), [
                'estado' => "required|in:registro,REGISTRO|string",
            ], [
                'estado.required' => "Estado es requerido para operación 'venta'. Se espera 'registro'.",
                'estado.in' => "No existe el estado '$request->estado para operación 'venta'. Se espera 'registro'.",
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'error' => $validator->errors()->all(),
                ], 400);
            }
        }

        if ($ambiente == "certificacion")
            $ambiente = 'c';
        else if ($ambiente == "produccion")
            $ambiente = '';
        else return response()->json([
            'error' => "Ambiente inválido. Se espera 'certificacion' o 'produccion'.",
        ], 400);

        // Obtener firma
        list($cert_path, $Firma) = $this->importarFirma($tmp_dir, base64_decode($request->firmab64), base64_decode($request->pswb64));
        if (is_array($Firma)) {
            return response()->json([
                'error' => $Firma['error'],
            ], 400);
        }

        // Llamar a función obtenerResumenCompraVenta o obtenerDetalleCompraVenta
        $metodo = "obtener".ucfirst(strtolower($request->tipo))."CompraVenta";
        $csv = $this->$metodo($ambiente, $cert_path, base64_decode($request->pswb64), $request->rut, $request->dv, $request->tipo_dte, strtoupper($request->estado), strtoupper($request->operacion), $request->periodo);
        if (!$csv) {
            return response()->json([
                'error' => 'No se pudo obtener csv',
            ], 400);
        }

        return response()->json([
            'csv' => $csv,
        ], 200);
    }

    public function consultaRegistroCompraVenta(Request $request, $ambiente): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'operacion' => 'required|in:recepcion,cedible,eventos,RECEPCION,CEDIBLE,EVENTOS,|string',
            'rut' => 'required|string',
            'dv' => 'required|string',
            'tipo_dte' => 'required|integer',
            'folio' => 'required|integer',
            'firmab64' => 'required|string',
            'pswb64' => 'required|string'
        ], [
            'rut' => 'rut es requerido',
            'dv' => 'dv es requerido',
            'tipo_dte' => 'tipo_dte es requerido',
            'folio' => 'folio es requerido',
            'firmab64.required' => 'firmab64 es requerida',
            'pswb64.required' => 'pswb64 es requerida',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        // Obtener firma
        list($cert_path, $Firma) = $this->importarFirma($tmp_dir, base64_decode($request->firmab64), base64_decode($request->pswb64));
        if (is_array($Firma)) {
            return response()->json([
                'error' => $Firma['error'],
            ], 400);
        }

        // verificar Token SII
        $rut_envia = $Firma->getID();
        try {
            $this->isToken($rut_envia, $Firma);
        } catch (Exception $e) {
            return response()->json([
                'error' => "No se pudo obtener Token SII. ". $e->getMessage(),
            ], 500);
        }

        // Set ambiente
        $this->setAmbiente($ambiente, $rut_envia);

        // Envío de respuesta de documento a SII
        try {
            $respuesta_doc = new \sasco\LibreDTE\Sii\RegistroCompraVenta($Firma);
        } catch (Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }

        if (strtoupper($request->operacion) == 'RECEPCION') {
            $response = $respuesta_doc->consultarFechaRecepcionSii($request->rut, $request->dv, $request->tipo_dte, $request->folio);
            if(!$response) {
                return response()->json([
                    'error' => "No se pudo realizar la solicitud"
                ], 400);
            }
            return response()->json([
                'exito' => [[
                    'fecha' => $response
                ]]
            ], 200);
        } else if (strtoupper($request->operacion) == 'CEDIBLE') {
            $response = $respuesta_doc->consultarDocDteCedible($request->rut, $request->dv, $request->tipo_dte, $request->folio);
            if(!$response) {
                return response()->json([
                    'error' => "No se pudo realizar la solicitud"
                ], 400);
            }
            return response()->json([
                'exito' => [$response]
            ], 200);
        } else if (strtoupper($request->operacion) == 'EVENTOS') {
            try {
                $response = $respuesta_doc->listarEventosHistDoc($request->rut, $request->dv, $request->tipo_dte, $request->folio);
                if(!$response) {
                    return response()->json([
                        'error' => "No se pudo realizar la solicitud"
                    ], 400);
                }
            } catch (Exception $e){
                $response = $e->getMessage();
            }
        }
        if(!$response) {
            return response()->json([
                'error' => "No se pudo realizar la solicitud"
            ], 400);
        }

        return response()->json([
            'exito' => $response
        ], 200);
    }

    public function generarFakePDF(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'Caratula' => 'required',
            'Documentos' => 'required|array',
            'Documentos.*.Encabezado.IdDoc.TipoDTE' => 'required|integer',
            'Documentos.*.Encabezado.IdDoc.Folio' => 'required|integer',
            'Cafs' => 'required|array',
            'firmab64' => 'required|string',
            'pswb64' => 'required|string',
        ], [
            'Caratula.required' => 'Caratula es requerida',
            'Documentos.required' => 'Documentos es requerido',
            'Documentos.*.Encabezado.IdDoc.TipoDTE.required' => 'TipoDTE es requerido',
            'Documentos.*.Encabezado.IdDoc.TipoDTE.integer' => 'TipoDTE debe ser un número entero',
            'Documentos.*.Encabezado.IdDoc.Folio.integer' => 'Folio debe ser un número entero',
            'Documentos.*.Encabezado.IdDoc.Folio.required' => 'Folio es requerido',
            'Cafs.required' => 'Cafs es requerido',
            'Cafs.array' => 'Cafs debe ser un arreglo',
            'firmab64.required' => 'firmab64 es requerida',
            'pswb64.required' => 'pswb64 es requerida',
        ]);

        // Si falla la validación, retorna una respuesta Json con el error
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->all(),
            ], 400);
        }

        // Obtener json
        $dte = $request->json()->all();

        // Obtener firma
        list($cert_path, $Firma) = $this->importarFirma($tmp_dir, base64_decode($request->firmab64), base64_decode($request->pswb64));
        if (is_array($Firma)) {
            return response()->json([
                'error' => $Firma['error'],
            ], 400);
        }

        /*
        $tipo_dte = $dte['Documentos'][0]['Encabezado']['IdDoc']['TipoDTE'];
        $folio = $dte['Documentos'][0]['Encabezado']['IdDoc']['Folio'];

        // Crear fake Caf a partir de Folios
        $rut = $dte['Documentos'][0]['Encabezado']['Emisor']['RUTEmisor'];
        $razon_social = $dte['Documentos'][0]['Encabezado']['Emisor']['RUTEmisor'];
        $fecha_emision = $dte['Documentos'][0]['Encabezado']['IdDoc']['FchEmis'];

        // Generar fake caf (por arreglar)
        $caf = $this->generarFakeCaf($rut, $razon_social, $tipo_dte, $folio, $fecha_emision, $dte['Cafs'][0], $Firma, $cert_path);
        if (!$caf)
            return response()->json([
                'error' => "No se pudo generar Fake Caf",
            ], 400);

        $caf_arr = [
            base64_encode($caf)
        ];*/

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
            /** @var Folios $Folio */
            if (!$Folio->check()) {
                return response()->json([
                    'error' => ["Error al leer CAF", Log::read()],
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

        // generar cada DTE, timbrar, firmar y agregar al envío

        $caratula = $this->obtenerCaratula(json_decode(json_encode($dte)), $dte['Documentos'], $Firma);

        //Obtener folio utilizable según CAF y reemplazarlo en json
        $folio = $dte['Documentos'][0]['Encabezado']['IdDoc']['Folio'];
        $dte['Documentos'][0]['Encabezado']['IdDoc']['Folio'] = $Folios[$dte['Documentos'][0]['Encabezado']['IdDoc']['TipoDTE']]->getDesde();

        $documentos = $dte['Documentos'];
        $dte_xml = $this->generarEnvioDteXml($documentos, $Firma, $Folios, $caratula);
        if(is_array($dte_xml) || !$dte_xml) {
            return response()->json([
                'error' => "Error al generar el XML",
                'message' => $dte_xml,
            ], 400);
        }

        // Reemplazar por nro folio y totales dados
        $dte_xml = new SimpleXMLElement($dte_xml);
        $dte_xml->children()->SetDTE->DTE->Documento[0]->Encabezado->IdDoc->Folio = $folio;
        if (isset($dte['Documentos'][0]['Encabezado']['Totales']['MntNeto'])) {
            $dte_xml->children()->SetDTE->DTE->Documento[0]->Encabezado->Totales->MntNeto = $dte['Documentos'][0]['Encabezado']['Totales']['MntNeto'];
        }
        if (isset($dte['Documentos'][0]['Encabezado']['Totales']['MntExe'])) {
            $dte_xml->children()->SetDTE->DTE->Documento[0]->Encabezado->Totales->MntExe = $dte['Documentos'][0]['Encabezado']['Totales']['MntExe'];
        }
        if (isset($dte['Documentos'][0]['Encabezado']['Totales']['IVA'])) {
            $dte_xml->children()->SetDTE->DTE->Documento[0]->Encabezado->Totales->IVA = $dte['Documentos'][0]['Encabezado']['Totales']['IVA'];
        }
        if (isset($dte['Documentos'][0]['Encabezado']['Totales']['TasaIVA'])) {
            $dte_xml->children()->SetDTE->DTE->Documento[0]->Encabezado->Totales->TasaIVA = $dte['Documentos'][0]['Encabezado']['Totales']['TasaIVA'];
        }
        if (isset($dte['Documentos'][0]['Encabezado']['Totales']['MntTotal'])) {
            $dte_xml->children()->SetDTE->DTE->Documento[0]->Encabezado->Totales->MntTotal = $dte['Documentos'][0]['Encabezado']['Totales']['MntTotal'];
        }

        $dte_xml = $dte_xml->asXML();
        // Generar PDF
        $base64_xml = base64_encode($dte_xml);

        // Asigna true si es 'H' False caso contrario
        $continuo = $request->formato_impresion == 'T';
        if(in_array($request->formato_impresion, array(0, 57, 70, 75, 77, 80, 110)))
            $continuo = $request->formato_impresion;

        // Llama a la función xmlPdf con los argumentos claros
        $pdfb64_arr = $this->xmlFakePdf($dte_xml, $continuo, $request->logob64, $request->observaciones, $request->cedible, $request->copia_cedible, $request->footer, $request->tickets);

        // Si hubo un error retornar error
        if (!$pdfb64_arr) {
            Log::write("No se pudo generar PDF");
            return response()->json([
                'error' => Log::read(),
            ], 400);
        }
        $pdfb64 = array_shift($pdfb64_arr);
        $pdfb64 = str_replace(array("\r", "\n"), '', $pdfb64);

        return response()->json([
            //'xmlb64' => $base64_xml,
            'pdfb64' => $pdfb64,
        ], 200);
    }
}

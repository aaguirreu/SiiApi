<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Dte;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use sasco\LibreDTE\Estado;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\XML;
use SimpleXMLElement;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Support\MessageCollection;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Support\AttachmentCollection;
use Webklex\PHPIMAP\Attachment;

// Debería ser class ApiFacturaController extends ApiController
// y llamar a FacturaController con use FacturaController, new FacturaController(construct)
class ApiFacturaController extends FacturaController
{
    public function __construct()
    {
        parent::__construct([33, 34, 56, 61]);
        $this->timestamp = Carbon::now('America/Santiago');
    }

    public function readLog()
    {
        return "readlog";
    }

    public function readMail()
    {

        //ProcessNewMail::dispatch();
        //return "dtemaillistener dispatched";

        $cm = new ClientManager(base_path().'/config/imap.php');

        //Connect to the IMAP Server
        $client = $cm->account('default');

        $client->connect();

        $folder = $client->getFolderByPath('Dtes');

        $query = $folder->messages();

        // Obtener último mensaje, la respuesta es MessageCollection, al obtener el primero con [0] se obtiene como Message
        $messageCollection = $query->all()->limit($limit = 1, $page = 1)->get();
        $message = $messageCollection[0];

        //return $message;
        // Obtener header
        //return var_dump($message->getHeader());

        // Obtener body
        //return $message->getTextBody();

        // Obtener adjuntos
        if ($message->hasAttachments()) {
            $attachmentsInfo = [];

            $attachments = $message->getAttachments();
            foreach ($attachments as $attachment) {
                // Obtener el contenido del adjunto
                $content = $attachment->getContent();

                // Convertir el contenido a UTF-8 (solo para mostrar por pantalla)
                $utf8Content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

                $attachmentInfo = [
                    'filename' => $attachment->getFilename(),
                    'content' => $utf8Content,
                ];

                $attachmentsInfo[] = $attachmentInfo;
            }
            // Devolver la información de los adjuntos
            return $attachmentsInfo;
        } else {
            return "No hay adjuntos";
        }
    }

    public function enviarXML(Request $request)
    {
        // Leer xml
        //$xml = new SimpleXMLElement($request->getContent());
        $xml = file_get_contents("Z:\SOPORTE\MANUALES\SII MANUAL DEL DESARROLLADOR EXTERNO\Firmar C#\Factura.xml");
        $EnvioDTE = new Sii\EnvioDte();
        $EnvioDTE->loadXML($xml);
        $Firma = $this->obtenerFirma();
        $EnvioDTE->setFirma($Firma);
        $EnvioDTExml = $EnvioDTE->generar();
        if ($EnvioDTE->schemaValidate()) {
            //return $EnvioDTExml;
        } else {
            // si hubo errores mostrar
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return response()->json([
                'message' => 'Error al validar el XML',
                'errors' => json_decode(json_encode($errores))
            ], 400);
        }

        $RutEnvia = $Firma->getID(); // RUT autorizado para enviar DTEs
        $RutEmisor = '76974300-6'; // RUT del emisor del DTE
        $response = $this->enviar($RutEnvia, $RutEmisor, $EnvioDTExml);
        return response()->json([
            'message' => 'XML enviado correctamente',
            'response' => $response
        ]);
    }

    public function facturaElectronica(Request $request)
    {
        // Leer string como json
        $dte = json_decode(json_encode($request->json()->all()));

        // Renovar token si es necesario
        $this->isToken();

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
        $EnvioDTExml = $this->generarEnvioDteXml($documentos, $firma, $folios, $caratula);
        if(is_array($EnvioDTExml)){
            return response()->json([
                'message' => "Error al generar el envio de DTEs",
                'errores' => json_decode(json_encode($EnvioDTExml)),
            ], 400);
        }

        return var_dump($EnvioDTExml);

        // Enviar DTE e insertar en base de datos de ser exitoso
        $envioArray = $this->enviar($caratula['RutEnvia'], $caratula['RutEmisor'], $EnvioDTExml);
        $envioResponse= $envioArray[0];
        $filename = $envioArray[1];
        if ($envioResponse == false) {
            return response()->json([
                'message' => "Error al enviar el DTE",
                'errores' => Log::read()->msg,
            ], 400);
        }

        // Guardar en base de datos envio, xml, etc
        $this->guardarXmlDB($envioResponse, $filename, $caratula, $dte);

        if($envioResponse->STATUS != '0') {
            return response()->json([
                'message' => "Error en la respuesta del SII al enviar factura",
                'errores' => $envioResponse,
            ], 400);
        }

        return response()->json([
            'message' => "Factura electronica enviada correctamente",
            'response' => [
                "EnvioFactura" => $envioResponse,
            ],
        ], 200);

        /*
        // Actualizar folios en la base de datos
        $this->actualizarFolios();
        return response()->json([
            //'message' => "Factura electronica y rcof enviado correctamente",
            'message' => "Factura electronica enviada correctamente",
            'response' => [
                "EnvioFactura" => json_decode($envioResponse),
                //'EnvioRcof' => json_decode(json_encode(["trackid" => $rcofreponse]))
            ],
        ], 200);
        */
    }

    public function estadoDteEnviado(Request $request)
    {
        // setear timestamp
        $this->timestamp = Carbon::now('America/Santiago');

        // Renovar token si es necesario
        $this->isToken();
        $token = json_decode(file_get_contents(base_path('config.json')))->token_dte;

        // Leer string como json
        $rbody = json_encode($request->json()->all());

        // Transformar a json
        $body = json_decode($rbody);

        // Schema del json
        //$schemaJson = file_get_contents(base_path().'\SchemasSwagger\SchemaStatusBE.json');

        // Validar json
        //$schema = Schema::import(json_decode($schemaJson));
        //$schema->in($body);

        // consultar estado dte
        $rut = $body->rut;
        $dv = $body->dv;
        $trackID = $body->trackID;

        // Set ambiente certificacón (default producción)
        //Sii::setAmbiente(Sii::CERTIFICACION);

        $xml = Sii::request('QueryEstUp', 'getEstUp', [$rut, $dv, $trackID, $token]);
        // si el estado se pudo recuperar se muestra estado y glosa

        return $xml->asXML();
        /*
        if ($xml->STATUS!=0) {
            Log::write(
                $xml->STATUS,
                Estado::get($xml->STATUS).(isset($xml->DETAIL)?'. '.implode("\n", (array)$xml->DETAIL->ERROR):'')
            );
            $arrayData = json_decode(json_encode($xml), true);
            $json_response = json_decode(json_encode($arrayData, JSON_PRETTY_PRINT));
            return response()->json([
                'message' => "Error en la respuesta del SII al consultar estado de DTE",
                'response' =>  $json_response,
            ], 200);
        }

        // Convertir a array asociativo
        $arrayData = json_decode(json_encode($xml), true);

        // Respuesta como JSON
        $json_response = json_decode(json_encode($arrayData, JSON_PRETTY_PRINT));

        return response()->json([
            'message' => "Estado de DTE consultado correctamente",
            'response' =>  $json_response,
        ], 200);
        /*
        return $estado->asXML();
        if ($estado!==false) {
            print_r([
                'codigo' => (string)$estado->xpath('/SII:RESPUESTA/SII:RESP_HDR/ESTADO')[0],
                //'glosa' => (string)$estado->xpath('/SII:RESPUESTA/SII:RESP_HDR/GLOSA')[0],
            ]);
        }

        // mostrar error si hubo
        foreach (Log::readAll() as $error)
            echo $error,"\n";
        */
    }

    public function estadoDte(Request $request)
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
        $token = json_decode(file_get_contents(base_path('config.json')))->token_dte;

        // Set ambiente certificacón (default producción)
        //SII::setAmbiente(SII::CERTIFICACION);

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
            'token'             => $token,
        ]);

        return $xml->asXML();

        //return $xml->asXML();

        // Convertir a array asociativo
        $arrayData = json_decode(json_encode($xml), true);

        // Respuesta como JSON
        $json_response = json_decode(json_encode($arrayData, JSON_PRETTY_PRINT));

        return response()->json([
            'message' => 'Estado de DTE obtenido correctamente',
            'response' => [
                "EstadoDte" => json_decode(json_encode($json_response), true)
            ],
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

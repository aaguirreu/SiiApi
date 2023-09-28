<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use sasco\LibreDTE\Sii;

class ApiFacturaController extends FacturaController
{
    public function __construct()
    {
        parent::__construct([33]);
        $this->timestamp = Carbon::now('America/Santiago');
    }

    public function facturaElectronica(Request $request)
    {
        // Leer string como json
        $dte = json_decode(json_encode($request->json()->all()));

        // Schema del json
        $schemaJson = file_get_contents(base_path() . '\SchemasSwagger\SchemaBoleta.json');

        // Validar json
        //$schema = Schema::import(json_decode($schemaJson));
        //$schema->in($dte));

        //$jsonArr = var_dump($dte);

        // setear timestamp
        $this->timestamp = Carbon::now('America/Santiago');

        // Renovar token si es necesario
        $this->isToken();

        // Primer folio a usar para envio de set de pruebas

        // Comparar cantidad de folios usados con cantidad de folios disponibles

        // Obtiene los folios con la cantidad de folios usados desde la base de datos
        self::$folios_inicial = $this->obtenerFolios();

        // Variable auxiliar para guardar el folio inicial

        // Obtener caratula
        $caratula = $this->obtenerCaratula($dte);

        // Obtener folios del Caf
        $Folios = $this->obtenerFoliosCaf();

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

        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $EnvioDTExml = $this->generarEnvioDteXml($boletas, $Firma, $Folios, $caratula);
        if(gettype($EnvioDTExml) == 'array'){
            return response()->json([
                'message' => "Error al generar el envio de DTEs",
                'errores' => json_decode(json_encode($EnvioDTExml)),
            ], 400);
        }

        // Enviar DTE e insertar en base de datos de ser exitoso
        $RutEnvia = $Firma->getID(); // RUT autorizado para enviar DTEs
        $RutEmisor = $boletas[0]['Encabezado']['Emisor']['RUTEmisor']; // RUT del emisor del DTE
        $dteresponse = $this->enviar($RutEnvia, $RutEmisor, $EnvioDTExml);

        return response()->json([
            //'message' => "Factura electronica y rcof enviado correctamente",
            'message' => "Factura electronica enviada correctamente",
            'response' => [
                "EnvioFactura" => $dteresponse,
                //'EnvioRcof' => json_decode(json_encode(["trackid" => $rcofreponse]))
            ],
        ], 200);

        /*
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

        // generar rcof (consumo de folios) y enviar
        $ConsumoFolioxml = $this->generarRCOF($EnvioDTExml);

        // Si hubo errores mostrarlos
        if (gettype($ConsumoFolioxml) == 'array') {
            return response()->json([
                'message' => "Error al generar el envio de Rcof (Consumo de folios)",
                'errores' => json_decode(json_encode($ConsumoFolioxml)),
            ], 400);
        }

        // Enviar RCOF e insertar en base de datos de ser exitoso
        $filename = 'EnvioBOLETA_' . $this->timestamp . '.xml';
        $filename = str_replace(' ', 'T', $filename);
        $filename = str_replace(':', '-', $filename);
        $rcofreponse = $this->enviarRcof($ConsumoFolioxml, $filename);

        // Actualizar folios en la base de datos
        $this->actualizarFolios();
        return response()->json([
            //'message' => "Factura electronica y rcof enviado correctamente",
            'message' => "Factura electronica enviada correctamente",
            'response' => [
                "EnvioFactura" => json_decode($dteresponse),
                //'EnvioRcof' => json_decode(json_encode(["trackid" => $rcofreponse]))
            ],
        ], 200);
        */
    }

    public function estadoDteEnviado(Request $request)
    {
        /** @var \Webklex\PHPIMAP\Client $client */
        $client = Webklex\IMAP\Facades\Client::account('default');

//Connect to the IMAP Server
        $client->connect();

//Get all Mailboxes
        /** @var \Webklex\PHPIMAP\Support\FolderCollection $folders */
        $folders = $client->getFolders();

//Loop through every Mailbox
        /** @var \Webklex\PHPIMAP\Folder $folder */
        foreach($folders as $folder){

            //Get all Messages of the current Mailbox $folder
            /** @var \Webklex\PHPIMAP\Support\MessageCollection $messages */
            $messages = $folder->messages()->all()->get();

            /** @var \Webklex\PHPIMAP\Message $message */
            foreach($messages as $message){
                echo $message->getSubject().'<br />';
                echo 'Attachments: '.$message->getAttachments()->count().'<br />';
                echo $message->getHTMLBody();

                //Move the current Message to 'INBOX.read'
                if($message->move('INBOX.read') == true){
                    echo 'Message has ben moved';
                }else{
                    echo 'Message could not be moved';
                }
            }
        }
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

        // Set ambiente producción
        //Sii::setAmbiente(Sii::CERTIFICACION);

        $estado = Sii::request('QueryEstUp', 'getEstUp', [$rut, $dv, $trackID, $token]);
        // si el estado se pudo recuperar se muestra estado y glosa

        return $estado->asXML();
        if ($estado!==false) {
            print_r([
                'codigo' => (string)$estado->xpath('/SII:RESPUESTA/SII:RESP_HDR/ESTADO')[0],
                //'glosa' => (string)$estado->xpath('/SII:RESPUESTA/SII:RESP_HDR/GLOSA')[0],
            ]);
        }

        // mostrar error si hubo
        foreach (\sasco\LibreDTE\Log::readAll() as $error)
            echo $error,"\n";
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

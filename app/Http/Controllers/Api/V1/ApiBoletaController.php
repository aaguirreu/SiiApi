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

    public function boletaElectronica(Request $request, $ambiente): JsonResponse
    {
        // Leer string como json
        $dte = json_decode(json_encode($request->json()->all()));

        // Set ambiente certificacón
        $this->setAmbiente($ambiente);

        // Verificar si existe empresa
        $empresa = DB::table('empresa')->where('rut', '=', $dte->Documentos[0]->Encabezado->Emisor->RUTEmisor)->first();
        if (!$empresa) {
            return response()->json([
                'message' => "Error al encontrar la empresa",
                'error' => "No existe empresa con el rut " . $dte->Documentos[0]->Encabezado->Emisor->RUTEmisor,
            ], 400);
        }

        // Verificar la empresa es cliente
        $cliente = DB::table('cliente')->where('empresa_id', '=', $empresa->id)->first();
        if (!$cliente) {
            return response()->json([
                'message' => "Error al encontrar el cliente",
                'error' => "No existe cliente con el rut " . $dte->Encabezado->Receptor->RUTRecep,
            ], 400);
        }

        // Obtiene los folios con la cantidad de folios usados desde la base de datos
        self::$folios_inicial = $this->obtenerFolios($dte, $empresa->id);
        if (isset(self::$folios_inicial['error'])) {
            return response()->json([
                'message' => "Error al obtener tipo de folios",
                'errores' => self::$folios_inicial['error']
            ], 400);
        }

        // Obtener folios del Caf
        $folios = $this->obtenerFoliosCaf($empresa->id, $empresa->rut);
        if (isset($folios['error'])) {
            return response()->json([
                'message' => "Error al obtener folios desde el CAF",
                'errores' => $folios['error']
            ], 400);
        }

        // Parseo de boletas según modelo libreDTE
        $boletas = $this->parseDte($dte, $empresa->id);

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

        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $envio_dte_xml = $this->generarEnvioDteXml($boletas, $Firma, $folios, $caratula);
        if (gettype($envio_dte_xml) == 'array') {
            return response()->json([
                'message' => "Error al generar el envio de DTEs",
                'errores' => json_decode(json_encode($envio_dte_xml)),
            ], 400);
        }

        // Enviar DTE e insertar en base de datos de ser exitoso
        $rut_envia = $Firma->getID(); // RUT autorizado para enviar DTEs
        $rut_emisor = $boletas[0]['Encabezado']['Emisor']['RUTEmisor']; // RUT del emisor del DTE
        list($envio_response, $filename) = $this->enviar($rut_envia, $rut_emisor, $envio_dte_xml);
        if (!$envio_response) {
            return response()->json([
                'message' => "Error al enviar la boleta",
                'errores' => Log::read()->msg,
            ], 400);
        }

        // Guardar en tabla envio_dte
        // Se separa de guardarXmlDB por que esta función se utiliza para guardar compras y ventas
        // los dte recibidos (compras) no tienen envio_id
        $envioDteId = $this->guardarEnvioDte($envio_response);

        // Guardar en base de datos envio, xml, etc
        $dbresponse = $this->guardarXmlDB($envioDteId, $filename, $caratula, $envio_dte_xml);
        if (isset($dbresponse['error'])) {
            return response()->json([
                'message' => "Error al guardar el DTE en la base de datos",
                'errores' => $dbresponse['error'],
            ], 400);
        }

        // Actualizar folios en la base de datos
        $this->actualizarFolios($empresa->id);
        return response()->json([
            'message' => "Boleta electronica enviada correctamente",
            'response' => [
                "EnvioBoleta" => $envio_response,
                //'EnvioRcof' => json_decode(json_encode(["trackid" => $rcofreponse]))
            ],
        ], 200);
    }

    public function estadoDteEnviado(Request $request, $ambiente): JsonResponse
    {
        // Set ambiente certificacón
        $this->setAmbiente($ambiente);

        // Leer string como json
        $rbody = json_encode($request->json()->all());

        // Transformar a json
        $body = json_decode($rbody);

        // consultar estado dte
        $rut = $body->rut;
        $dv = $body->dv;
        $trackID = $body->track_id;
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
        return response()->json([
            'response' => json_decode($response),
        ], 200);
    }

    public function estadoDte(Request $request, $ambiente): JsonResponse
    {
        // setear timestamp
        $this->timestamp = Carbon::now('America/Santiago');

        // Set ambiente certificacón
        $this->setAmbiente($ambiente);

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
        $fechaEmision = $body->fecha_emision;
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

    public function generarPdf(Request $request): JsonResponse {
        // Obtener xml del body de la request
        $xml = $request->getContent();

        // Cargar EnvioDTE y extraer arreglo con datos de carátula y DTEs
        $EnvioDte = new EnvioDte();
        $EnvioDte->loadXML($xml);
        $Caratula = $EnvioDte->getCaratula();
        $Documentos = $EnvioDte->getDocumentos();

        // procesar cada DTEs e ir agregándolo al PDF
        $DTE = $Documentos[0];
        $filename = 'dte_'.$Caratula['RutEmisor'].'_'.$DTE->getID();
        if (!$DTE->getDatos())
            //die('No se pudieron obtener los datos del DTE');
            return response()->json([
                'message' => 'No se pudieron obtener los datos del DTE'
            ], 400);

        $pdf = new \sasco\LibreDTE\Sii\Dte\PDF\Dte(true); // =false hoja carta, =true papel contínuo (false por defecto si no se pasa)
        $pdf->setFooterText();
        $pdf->setLogo('/vendor/sasco/website/webroot/img/logo_mini.png'); // debe ser PNG!
        $pdf->setResolucion(['FchResol'=>$Caratula['FchResol'], 'NroResol'=>$Caratula['NroResol']]);
        //$pdf->setCedible(true);
        $pdf->agregar($DTE->getDatos(), $DTE->getTED());
        //$pdf->Output(sys_get_temp_dir()."$filename.pdf", 'F');
        // entregar archivo comprimido que incluirá cada uno de los DTEs
        // PARA AHORRAR ESPACIO EN TMP DELETE DEBE SER TRUE!!!
        //\sasco\LibreDTE\File::compress(sys_get_temp_dir()."$filename.pdf", ['format'=>'zip', 'delete'=>true, 'download'=>false]);

        // Inicia secion con usuario host y password
        try {
            $port = 22;
            $sftp = new SFTP('host', $port);
            if (!$sftp->login('user', 'password')) {
                return response()->json([
                    'message' => 'Error al iniciar sesión',
                    'response' => $sftp->getExitStatus()
                ], 400);
            }

            $sftp->put("$filename.pdf", $pdf->getPDFData(), SCP::SOURCE_STRING);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'Error al enviar PDF Exception',
                'response' => $e->getTraceAsString()
            ], 400);
        }

        return response()->json([
            'message' => 'PDF enviado correctamente',
        ], 200);

    }
}

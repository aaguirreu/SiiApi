<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\Carbon;
use CURLFile;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use sasco\LibreDTE\Estado;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii\EnvioDte;
use SimpleXMLElement;

class FacturaController extends DteController
{
    public function __construct($tipos_dte)
    {
        self::$tipos_dte = $tipos_dte;
        self::isToken();
        self::$token = json_decode(file_get_contents(base_path('config.json')))->token;
    }

    /**
     * Enviar DTE al SII
     */
    protected function enviar($rutEnvia, $rutEmisor, $rutReceptor, $dte) {
        // definir datos que se usarán en el envío
        list($rutSender, $dvSender) = explode('-', str_replace('.', '', $rutEnvia));
        list($rutCompany, $dvCompany) = explode('-', str_replace('.', '', $rutEmisor));
        if (!str_contains($dte, '<?xml')) {
            $dte = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $dte;
        }

        list($file, $filename) = $this->parseFileName($rutEmisor, $rutReceptor);
        try {
            Storage::disk('dtes')->put("$rutEmisor/Envios/$rutReceptor/$filename", $dte);
        } catch (Exception $e) {
            Log::write(0, "Error al guardar dte en Storage. {$e->getMessage()}");
            return false;
        }

        $data = [
            'rutSender' => $rutSender,
            'dvSender' => $dvSender,
            'rutCompany' => $rutCompany,
            'dvCompany' => $dvCompany,
            'archivo' => new CURLFile($file, 'text/xml', $filename),
        ];

        $header = [
            'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Logiciel)',
            'Cookie: TOKEN=' . self::$token,
            'Content-Type: text/html; charset=ISO-8859-1',
        ];

        // crear sesión curl con sus opciones
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_URL, self::$url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        // enviar XML al SII
        for ($i=0; $i<self::$retry; $i++) {
            $response = curl_exec($curl);
            if ($response and $response!='Error 500') {
                break;
            }
        }

        // verificar respuesta del envío y entregar error en caso que haya uno
        if (!$response or $response=='Error 500') {
            if (!$response) {
                Log::write(Estado::ENVIO_ERROR_CURL, Estado::get(Estado::ENVIO_ERROR_CURL, curl_error($curl)));
            }
            if ($response == 'Error 500') {
                Log::write(Estado::ENVIO_ERROR_500, Estado::get(Estado::ENVIO_ERROR_500));
            }
            // Borrar xml guardado anteriormente
            Storage::disk('dtes')->delete($rutReceptor . '\\' . $filename);
            return false;
        }

        // cerrar sesión curl
        curl_close($curl);

        // crear XML con la respuesta y retornar
        try {
            $xml = new \SimpleXMLElement($response, LIBXML_COMPACT);
        } catch (Exception $e) {
            \sasco\LibreDTE\Log::write(Estado::ENVIO_ERROR_XML, Estado::get(Estado::ENVIO_ERROR_XML, $e->getMessage()));
            // Borrar xml guardado anteriormente
            Storage::disk('dtes')->delete($rutReceptor . '\\' . $filename);
            return false;
        }

        // Verificar si el envío fue correcto
        if ($xml->STATUS!=0) {
            \sasco\LibreDTE\Log::write(
                $xml->STATUS,
                Estado::get($xml->STATUS).(isset($xml->DETAIL)?'. '.implode("\n", (array)$xml->DETAIL->ERROR):'')
            );
            // Borrar xml guardado anteriormente
            Storage::disk('dtes')->delete($rutReceptor . '\\' . $filename);
            return false;
        }

        // Convertir a array asociativo
        $arrayData = json_decode(json_encode($xml), true);

        // Respuesta como JSON
        $json_response = json_decode(json_encode($arrayData, JSON_PRETTY_PRINT));

        return [$json_response, $filename];
    }

    /**
     * Recorre los documentos como array y les asigna un folio
     */
    protected function parseDte($dte): array
    {
        $documentos = [];
        $dte = json_decode(json_encode($dte), true);

        foreach ($dte["Documentos"] as $documento) {
            /*$modeloDocumento = [
                "Encabezado" => [
                    "IdDoc" => $documento["Encabezado"]["IdDoc"] ?? [],
                    "Emisor" => $documento["Encabezado"]["Emisor"] ?? [],
                    "Receptor" => $documento["Encabezado"]["Receptor"] ?? [],
                    "Totales" => $documento["Encabezado"]["Totales"] ?? [],
                    ],
                "Detalle" => $documento["Detalle"] ?? [],
                "Referencia" => $documento["Referencia"] ?? false,
                "DscRcgGlobal" => $documento["DscRcgGlobal"] ?? false,
            ];*/
            $modeloDocumento = $documento;

            if(!isset($modeloDocumento["Encabezado"]["IdDoc"]["TipoDTE"]))
                return ["error" => "Debe indicar el TipoDTE"];

            $tipoDte = $modeloDocumento["Encabezado"]["IdDoc"]["TipoDTE"];

            $modeloDocumento["Encabezado"]["IdDoc"]["Folio"] = ++self::$folios[$tipoDte];
            $documentos[] = $modeloDocumento;
        }

        // Compara si el número de folios restante en el caf es mayor o igual al número de documentos a enviar
        foreach (self::$folios as $key => $value) {
            $folio_final = DB::table('caf')->where('folio_id', '=', $key)->latest()->first()->folio_final;
            $cant_folio = DB::table('secuencia_folio')->where('id', '=', $key)->latest()->first()->cant_folios;
            $folios_restantes = $folio_final - $cant_folio;
            $folios_documentos = self::$folios[$key] - self::$folios_inicial[$key] + 1;
            if ($folios_documentos > $folios_restantes) {
                $response = [
                    'error' => 'No hay folios suficientes para generar los documentos',
                    'tipo_folio' => $key,
                    'máxmimo_rango_caf' => $folio_final,
                    'último_folio_utilizado' => $cant_folio,
                    'folios_restantes' => $folios_restantes,
                    'folios_a_utilizar' => $folios_documentos,
                ];
            }
        }
        return $response ?? $documentos;
    }

    /**
     * Generar DTE de respuesta sobre la recepción de un envío de DTE
     * @throws Exception
     */
    public function respuestaEnvio($attachment): bool|array
    {
        $this->timestamp = Carbon::now('America/Santiago');

        // Cargar EnvioDTE y extraer arreglo con datos de carátula y DTEs
        $EnvioDte = new EnvioDte();
        $EnvioDte->loadXML($attachment->getContent());
        $caratula = $EnvioDte->getCaratula();
        $Documentos = $EnvioDte->getDocumentos();

        // Verificar si RutReceptor está en la base de datos como cliente con join
        $cliente = DB::table('empresa')
            ->join('cliente', 'empresa.id', '=', 'cliente.empresa_id')
            ->where('empresa.rut', '=', $EnvioDte->getReceptor())
            ->select('empresa.rut') // Seleccionar solo el campo que necesitas
            ->first();

        if ($cliente) {
            // RutReceptor en el DTE.xml recibido
            $rut_receptor_esperado = $cliente->rut;
        } else {
            // RutReceptor en el DTE.xml recibido
            $rut_receptor_esperado = '000-0';
        }


        // Obtener el codigo de envio de la respuesta
        $respuesta = DB::table('secuencia_respuesta')->first();
        if(isset($respuesta)) {
            $cod_envio = $respuesta->cod_envio;
            $idRespuesta = $respuesta->id;
        } else {
            $cod_envio = 1;
            $idRespuesta = 1;
        }

        // RutEmisor en el DTE.xml recibido
        $rut_emisor_esperado = $caratula['RutEmisor'];

        // caratula
        $caratula_respuesta = [
            'RutResponde' => $rut_receptor_esperado,
            'RutRecibe' => $caratula['RutEmisor'],
            'IdRespuesta' => ++$idRespuesta,
            //'NmbContacto' => '',
            //'MailContacto' => '',
        ];

        // procesar cada DTE
        $recepcion_dte = [];
        foreach ($Documentos as $DTE) {
            $estado = $DTE->getEstadoValidacion(['RUTEmisor'=>$rut_emisor_esperado, 'RUTRecep'=>$rut_receptor_esperado]);
            $recepcion_dte[] = [
                'TipoDTE' => $DTE->getTipo(),
                'Folio' => $DTE->getFolio(),
                'FchEmis' => $DTE->getFechaEmision(),
                'RUTEmisor' => $DTE->getEmisor(),
                'RUTRecep' => $DTE->getReceptor(),
                'MntTotal' => $DTE->getMontoTotal(),
                'EstadoRecepDTE' => $estado,
                'RecepDTEGlosa' => \sasco\LibreDTE\Sii\RespuestaEnvio::$estados['documento'][$estado],
            ];
        }

        // armar respuesta de envío
        $estado = $EnvioDte->getEstadoValidacion(['RutReceptor'=>$rut_receptor_esperado]);
        $RespuestaEnvio = new \sasco\LibreDTE\Sii\RespuestaEnvio();
        $RespuestaEnvio->agregarRespuestaEnvio([
            'NmbEnvio' => $attachment->getName(),
            'CodEnvio' => ++$cod_envio,
            'EnvioDTEID' => $EnvioDte->getID(),
            'Digest' => $EnvioDte->getDigest(),
            'RutEmisor' => $EnvioDte->getEmisor(),
            'RutReceptor' => $EnvioDte->getReceptor(),
            'EstadoRecepEnv' => $estado,
            'RecepEnvGlosa' => \sasco\LibreDTE\Sii\RespuestaEnvio::$estados['envio'][$estado],
            'NroDTE' => count($recepcion_dte),
            'RecepcionDTE' => $recepcion_dte,
        ]);

        // asignar carátula y Firma
        $RespuestaEnvio->setCaratula($caratula_respuesta);
        $firma = $this->obtenerFirma();
        $RespuestaEnvio->setFirma($firma);

        // generar XML
        $xml = $RespuestaEnvio->generar();

        // validar schema del XML que se generó
        $filename = "RespuestaEnvio_{$cod_envio}_$this->timestamp.xml";
        $filename = str_replace(' ', 'T', $filename);
        $filename = str_replace(':', '-', $filename);
        if ($RespuestaEnvio->schemaValidate()) {
            // Guardar DTE en la base de datos
            // envioDteId null significa que un dte es de tipo recibido, de compra en este caso
            if($rut_receptor_esperado != '000-0'){
                $dbresponse = $this->guardarXmlDB(null, $attachment->getName(), $caratula, $attachment->getContent());
                if (isset($dbresponse['error'])) {
                    Log::write(0, $dbresponse['error']);
                    return false;
                }
                Storage::disk('dtes')->put("$rut_receptor_esperado/Recibidos/$rut_emisor_esperado/".$attachment->getName(), $attachment->getContent());
                Storage::disk('dtes')->put("$rut_receptor_esperado/Respuestas/$rut_emisor_esperado/$filename", $xml);
                $this->guardarRespuesta($dbresponse, $cod_envio, $filename);
            }
        }
        return [
            'filename' => $filename,
            'data' => $xml
        ];
    }

    /**
     * Generar DTE de respuesta sobre la aceptación o rechazo de un dte
     */
    public function respuestaDocumento($dte_id, $estado, $motivo, $dte_xml)
    {
        $this->timestamp = Carbon::now('America/Santiago');

        // Cargar EnvioDTE y extraer arreglo con datos de carátula y DTEs
        $EnvioDte = new \sasco\LibreDTE\Sii\EnvioDte();
        $EnvioDte->loadXML($dte_xml);
        $caratula = $EnvioDte->getCaratula();
        $Documentos = $EnvioDte->getDocumentos();

        // Verificar si RutReceptor está en la base de datos como cliente con join
        $cliente = DB::table('cliente')
            ->join('empresa', 'cliente.empresa_id', '=', 'empresa.id')
            ->where('empresa.rut', '=', $EnvioDte->getReceptor())
            ->first();
        if (isset($cliente)) {
            // RutReceptor en el DTE.xml recibido
            $rut_receptor_esperado = $cliente->rut;
        } else {
            // RutReceptor en el DTE.xml recibido
            $rut_receptor_esperado = '000-0';
        }

        // Obtener la codigo de envio asociado a la respuesta del dte
        $respuesta = DB::table('secuencia_respuesta')->where('dte_id', '=', $dte_id)->first();
        if(isset($respuesta)) {
            $cod_envio = $respuesta->cod_envio;
        } else {
            Log::write(0, 'No se encontró el código de envío asociado al dte');
            return false;
        }

        // caratula
        $rut_emisor_esperado = $EnvioDte->getEmisor();
        $caratula_respuesta = [
            'RutResponde' => $rut_receptor_esperado,
            'RutRecibe' => $rut_emisor_esperado,
            'IdRespuesta' => ++$respuesta->id,
            //'NmbContacto' => '',
            //'MailContacto' => '',
        ];

        // objeto para la respuesta
        $RespuestaEnvio = new \sasco\LibreDTE\Sii\RespuestaEnvio();

        // procesar cada DTE
        foreach ($Documentos as $DTE) {
            $RespuestaEnvio->agregarRespuestaDocumento([
                'TipoDTE' => $DTE->getTipo(),
                'Folio' => $DTE->getFolio(),
                'FchEmis' => $DTE->getFechaEmision(),
                'RUTEmisor' => $DTE->getEmisor(),
                'RUTRecep' => $DTE->getReceptor(),
                'MntTotal' => $DTE->getMontoTotal(),
                'CodEnvio' => ++$cod_envio,
                'EstadoDTE' => $estado,
                'EstadoDTEGlosa' => \sasco\LibreDTE\Sii\RespuestaEnvio::$estados['respuesta_documento'][$estado].$motivo,
            ]);
        }

        // asignar carátula y Firma
        $RespuestaEnvio->setCaratula($caratula_respuesta);
        $RespuestaEnvio->setFirma($this->obtenerFirma());

        // generar XML
        // generar XML
        $xml = $RespuestaEnvio->generar();

        $filename = "RespuestaDocumento_{$cod_envio}_$this->timestamp.xml";
        $filename = str_replace(' ', 'T', $filename);
        $filename = str_replace(':', '-', $filename);
        // validar schema del XML que se generó
        if ($RespuestaEnvio->schemaValidate()) {
            // Guardar respuesta en la base de datos
            Storage::disk('dtes')->put("$rut_receptor_esperado/Respuestas/$filename", $xml);
            $this->guardarRespuesta($dte_id, $cod_envio, $filename);
            return [
                'filename' => $filename,
                'data' => $xml
            ];
        }
        return false;
    }

    /**
     * Enviar respuesta sobre la recepción, aceptación o rechazo de un dte al SII
     * No se usa
     */
    public function enviarRespuestaSii($rutEnvia, $rutEmisor, $rutReceptor, $dte, $filename)
    {
        list($rutSender, $dvSender) = explode('-', str_replace('.', '', $rutEnvia));
        list($rutCompany, $dvCompany) = explode('-', str_replace('.', '', $rutEmisor));
        if (!str_contains($dte, '<?xml')) {
            $dte = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $dte;
        }

        $file = Storage::disk('dtes')->path("$rutReceptor/Respuestas/$filename");

        $data = [
            'rutSender' => $rutSender,
            'dvSender' => $dvSender,
            'rutCompany' => $rutCompany,
            'dvCompany' => $dvCompany,
            'archivo' => new CURLFile($file, 'text/xml', $filename),
        ];

        $header = [
            'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Logiciel)',
            'Cookie: TOKEN=' . self::$token,
            'Content-Type: text/html; charset=ISO-8859-1',
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_URL, 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        // enviar XML al SII
        for ($i=0; $i<self::$retry; $i++) {
            $response = curl_exec($curl);
            if ($response and $response!='Error 500') {
                break;
            }
        }

        // verificar respuesta del envío y entregar error en caso que haya uno
        if (!$response or $response=='Error 500') {
            if (!$response) {
                Log::write(Estado::ENVIO_ERROR_CURL, Estado::get(Estado::ENVIO_ERROR_CURL, curl_error($curl)));
            }
            if ($response == 'Error 500') {
                Log::write(Estado::ENVIO_ERROR_500, Estado::get(Estado::ENVIO_ERROR_500));
            }
            // Borrar xml guardado anteriormente
            Storage::disk('dtes')->delete($file);
            return false;
        }

        // cerrar sesión curl
        curl_close($curl);
        echo $response;
    }


    /**
     * Obtiene el correo del receptor desde la base de datos según rut
     */
    public function obtenerCorreoDB($rut_receptor)
    {
        try {
            DB::table('empresa')
                ->where('rut', '=', $rut_receptor)
                ->first()->correo;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Actualiza el correo del receptor en la base de datos según rut
     */
    public function actualizarCorreoDB($rut_receptor, $correo): void
    {
        DB::table('empresa')
            ->where('rut', '=', $rut_receptor)
            ->update(['correo' => $correo]);
    }

    public function obtenerCodigoRespuesta($id)
    {

    }

    public function obtenerIDRespuesta($id)
    {

    }

    /**
     * Guarda el xml en la base de datos
     */
    public function guardarRespuesta($dte_id, $cod_envio, $filename): void
    {
        DB::table('secuencia_respuesta')->insertGetId(
            [
                'dte_id' => $dte_id,
                'cod_envio' => $cod_envio,
                'xml_filename' => $filename,
                'updated_at' => $this->timestamp,
                'created_at' => $this->timestamp,
            ]
        );
    }
}

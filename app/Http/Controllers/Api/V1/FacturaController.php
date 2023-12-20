<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\Carbon;
use DOMDocument;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use sasco\LibreDTE\Estado;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\Sii\Autenticacion;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\XML;
use Psr\Http\Message\RequestInterface;

class FacturaController extends DteController
{
    public function __construct($tipos_dte)
    {
        self::$tipos_dte = $tipos_dte;
        self::isToken();
        self::$token = json_decode(file_get_contents(base_path('config.json')))->token;
    }

    protected function enviar($rutEnvia, $rutEmisor, $rutReceptor, $dte) {
        // definir datos que se usarán en el envío
        list($rutSender, $dvSender) = explode('-', str_replace('.', '', $rutEnvia));
        list($rutCompany, $dvCompany) = explode('-', str_replace('.', '', $rutEmisor));
        if (!str_contains($dte, '<?xml')) {
            $dte = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $dte;
        }

        list($file, $filename) = $this->parseFileName($rutReceptor);

        if(!Storage::disk('dtes')->put($rutReceptor.'/'.$filename, $dte)) {
            Log::write(0, 'Error al guardar dte en Storage');
            return false;
        }

        try {
            $headers = [
                'Cookie' => 'TOKEN='.self::$token,
                'text/xml;charset=ISO-8859-1',
            ];

            $options = [
                'multipart' => [
                    [
                        'name' => 'rutSender',
                        'contents' => $rutSender,
                    ],
                    [
                        'name' => 'dvSender',
                        'contents' => $dvSender,
                    ],
                    [
                        'name' => 'rutCompany',
                        'contents' => $rutCompany,
                    ],
                    [
                        'name' => 'dvCompany',
                        'contents' => $dvCompany,
                    ],
                    [
                        'name' => 'archivo',
                        'contents' => fopen($file, 'r'),
                        'filename' => $file,
                        'headers'  => [
                            'Content-Type' => 'application/xml;charset=ISO-8859-1',
                            'SOAPAction' => 'balance',
                        ],
                    ],
                ],
            ];

            $client = new Client();
            $request = new Request('POST', self::$url, $headers);

            // Enviar la solicitud de manera asíncrona y obtener la respuesta
            $promise = $client->sendAsync($request, $options)->then(
                function ($response) {
                    // Obtener el cuerpo de la respuesta
                    $body = $response->getBody()->getContents();
                    $response = new Response(200, [], $body);
                    // Aquí $body contiene la respuesta en formato XML
                    echo $response->getBody()->getContents();

                    // crear XML con la respuesta y retornar
                    try {
                        $xml = new \SimpleXMLElement($body, LIBXML_COMPACT);
                    } catch (Exception $e) {
                        \sasco\LibreDTE\Log::write(Estado::ENVIO_ERROR_XML, Estado::get(Estado::ENVIO_ERROR_XML, $e->getMessage()));
                        return false;
                    }
                    if ($xml->STATUS!=0) {
                        \sasco\LibreDTE\Log::write(
                            $xml->STATUS,
                            Estado::get($xml->STATUS).(isset($xml->DETAIL)?'. '.implode("\n", (array)$xml->DETAIL->ERROR):'')
                        );
                    }
                },
                function (RequestException $exception) {
                    // Manejar errores en la solicitud
                    echo "Error en la solicitud: " . $exception->getMessage();
                }
            );

            // Esperar a que la promesa se cumpla
            $promise->wait();
        } catch (Exception $e) {
            // Manejar otras excepciones
            echo "Error general: " . $e->getMessage();
        }


        // crear XML con la respuesta y retornar
        try {
            $xml = new \SimpleXMLElement($response, LIBXML_COMPACT);
        } catch (Exception $e) {
            \sasco\LibreDTE\Log::write(Estado::ENVIO_ERROR_XML, Estado::get(Estado::ENVIO_ERROR_XML, $e->getMessage()));
            return false;
        }
        if ($xml->STATUS!=0) {
            \sasco\LibreDTE\Log::write(
                $xml->STATUS,
                Estado::get($xml->STATUS).(isset($xml->DETAIL)?'. '.implode("\n", (array)$xml->DETAIL->ERROR):'')
            );
        }
        #echo $xml->asXML();

        // Convertir a array asociativo
        $arrayData = json_decode(json_encode($response), true);

        // Respuesta como JSON
        $json_response = json_decode(json_encode($arrayData, JSON_PRETTY_PRINT));

        return [$json_response, $filename];
    }

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

    public function respuestaEnvio($attachment): bool|array
    {
        $this->timestamp = Carbon::now('America/Santiago');

        // RutReceptor en el DTE.xml recibido
        $rut_receptor_esperado = env('RUT_EMISOR', '000-0');

        // Cargar EnvioDTE y extraer arreglo con datos de carátula y DTEs
        $EnvioDte = new EnvioDte();
        $EnvioDte->loadXML($attachment->getContent());
        $Caratula = $EnvioDte->getCaratula();
        $Documentos = $EnvioDte->getDocumentos();

        // RutEmisor en el DTE.xml recibido
        $rut_emisor_esperado = $Caratula['RutEmisor'];

        // caratula
        $caratula = [
            'RutResponde' => $rut_receptor_esperado,
            'RutRecibe' => $Caratula['RutEmisor'],
            'IdRespuesta' => 1,
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
            'CodEnvio' => 1,
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
        $RespuestaEnvio->setCaratula($caratula);
        $RespuestaEnvio->setFirma($this->obtenerFirma());

        // generar XML
        $xml = $RespuestaEnvio->generar();

        // validar schema del XML que se generó
        if ($RespuestaEnvio->schemaValidate()) {
            // mostrar XML al usuario, deberá ser guardado y subido al SII en:
            // https://www4.sii.cl/pfeInternet
            $filename = 'RespuestaEnvio.xml';
            Storage::disk('dtes')->put("Respuestas\\$rut_emisor_esperado\\$filename", $xml);
            return [
                'filename' => $filename,
                'data' => $xml
            ];
        }
        return false;
    }

    public function respuestaDocumento($dte_xml, $rut_emisor_esperado)
    {
        $this->timestamp = Carbon::now('America/Santiago');

        // datos para validar
        $rut_receptor_esperado = env('RUT_EMISOR', '000-0');

        // Cargar EnvioDTE y extraer arreglo con datos de carátula y DTEs
        $EnvioDte = new \sasco\LibreDTE\Sii\EnvioDte();
        $EnvioDte->loadXML($dte_xml);
        $Caratula = $EnvioDte->getCaratula();
        $Documentos = $EnvioDte->getDocumentos();

        // Obtener el id de la respuesta
        $id_respuesta = DB::table('secuencia_folio')
            ->where('id', '=', 1)
            ->first();
        if(isset($id_respuesta)) {
            $id_respuesta = $id_respuesta->cant_folios;
        } else {
            DB::table('secuencia_folio')
                ->insert([
                    'id' => 1,
                    'cant_folios' => 0,
                    'updated_at' => $this->timestamp,
                    'created_at' => $this->timestamp,
                ]);
        }

        // caratula
        $caratula = [
            'RutResponde' => $rut_receptor_esperado,
            'RutRecibe' => $rut_emisor_esperado,
            'IdRespuesta' => 1,
            //'NmbContacto' => '',
            //'MailContacto' => '',
        ];

        // objeto para la respuesta
        $RespuestaEnvio = new \sasco\LibreDTE\Sii\RespuestaEnvio();

        // Obtener el código de envío
        $cod_envio = DB::table('secuencia_folio')
            ->where('id', '=', 0)
            ->first();
        if(isset($cod_envio)) {
            $cod_envio = $cod_envio->cant_folios;
        } else {
            DB::table('secuencia_folio')
                ->insert([
                    'id' => 0,
                    'cant_folios' => 0,
                    'updated_at' => $this->timestamp,
                    'created_at' => $this->timestamp,
                ]);
        }
        foreach ($Documentos as $DTE) {
            $estado = !$DTE->getEstadoValidacion(['RUTEmisor'=>$rut_emisor_esperado, 'RUTRecep'=>$rut_receptor_esperado]) ? 0 : 2;
            $RespuestaEnvio->agregarRespuestaDocumento([
                'TipoDTE' => $DTE->getTipo(),
                'Folio' => $DTE->getFolio(),
                'FchEmis' => $DTE->getFechaEmision(),
                'RUTEmisor' => $DTE->getEmisor(),
                'RUTRecep' => $DTE->getReceptor(),
                'MntTotal' => $DTE->getMontoTotal(),
                'CodEnvio' => ++$cod_envio,
                'EstadoDTE' => $estado,
                'EstadoDTEGlosa' => \sasco\LibreDTE\Sii\RespuestaEnvio::$estados['respuesta_documento'][$estado],
            ]);
        }

        // asignar carátula y Firma
        $RespuestaEnvio->setCaratula($caratula);
        $RespuestaEnvio->setFirma($this->obtenerFirma());

        // generar XML
        // generar XML
        $xml = $RespuestaEnvio->generar();

        // validar schema del XML que se generó
        if ($RespuestaEnvio->schemaValidate()) {
            // mostrar XML al usuario, deberá ser guardado y subido al SII en:
            // https://www4.sii.cl/pfeInternet
            $filename = "RespuestaDocumento_$cod_envio.xml";
            Storage::disk('dtes')->put("Respuestas\\$rut_emisor_esperado\\$filename", $xml);
            DB::table('secuencia_folio')
                ->where('id', '=', 0)
                ->update([
                    'cant_folios' => $cod_envio,
                    'updated_at' => $this->timestamp,
                ]);
            return [
                'filename' => $filename,
                'data' => $xml
            ];
        }
        return false;
    }

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
}

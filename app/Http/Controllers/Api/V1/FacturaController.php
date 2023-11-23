<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\Carbon;
use CURLFile;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use sasco\LibreDTE\Estado;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\Sii\Autenticacion;
use sasco\LibreDTE\Sii\EnvioDte;

class FacturaController extends DteController
{
    public function __construct($tipos_dte)
    {
        self::$tipos_dte = $tipos_dte;
        self::isToken();
        self::$token = json_decode(file_get_contents(base_path('config.json')))->token;
    }

    protected function enviar($rutEnvia, $rutEmisor, $dte) {
        // definir datos que se usarán en el envío
        list($rutSender, $dvSender) = explode('-', str_replace('.', '', $rutEnvia));
        list($rutCompany, $dvCompany) = explode('-', str_replace('.', '', $rutEmisor));
        if (!str_contains($dte, '<?xml')) {
            $dte = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $dte;
        }
        do {
            list($file, $filename) = $this->guardarXML('60803000-K');
        } while (file_exists($file));

        if(!Storage::disk('dtes')->put("60803000-K\\$filename", $dte)) {
            Log::write(0, 'Error al guardar dte en Storage');
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
            if ($response=='Error 500') {
                Log::write(Estado::ENVIO_ERROR_500, Estado::get(Estado::ENVIO_ERROR_500));
            }
            // Borrar xml guardado anteriormente
            Storage::disk('dtes')->delete('60803000-K\\'.$filename);
            return false;
        }

        // cerrar sesión curl
        curl_close($curl);

        // crear XML con la respuesta y retornar
        try {
            $xml = new \SimpleXMLElement($response, LIBXML_COMPACT);
        } catch (Exception $e) {
            Log::write(Estado::ENVIO_ERROR_XML, Estado::get(Estado::ENVIO_ERROR_XML, $e->getMessage()));
            return false;
        }
        if ($xml->STATUS!=0) {
            Log::write(
                $xml->STATUS,
                Estado::get($xml->STATUS).(isset($xml->DETAIL)?'. '.implode("\n", (array)$xml->DETAIL->ERROR):'')
            );
            $arrayData = json_decode(json_encode($xml), true);
            // Borrar xml guardado anteriormente
            Storage::disk('dtes')->delete('60803000-K\\'.$filename);
            return false;
        }

        // Convertir a array asociativo
        $arrayData = json_decode(json_encode($xml), true);

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

    public function respuestaDte($attachment)
    {
        // EJEMPLO
        // Rut Contribuyente (EMPRESA) que redacta la respuesta
        $RutReceptor_esperado = '76974300-6';
        // Rut de quien envió el DTE y espera una respuesta
        $RutEmisor_esperado = '77614933-0';

        // Cargar EnvioDTE y extraer arreglo con datos de carátula y DTEs
        $EnvioDte = new EnvioDte();
        $EnvioDte->loadXML($attachment->getContent());
        $Caratula = $EnvioDte->getCaratula();
        $Documentos = $EnvioDte->getDocumentos();

        // caratula
        $caratula = [
            'RutResponde' => $RutReceptor_esperado,
            'RutRecibe' => $Caratula['RutEmisor'],
            'IdRespuesta' => 1,
            //'NmbContacto' => '',
            //'MailContacto' => '',
        ];

        // procesar cada DTE
        $RecepcionDTE = [];
        foreach ($Documentos as $DTE) {
            $estado = $DTE->getEstadoValidacion(['RUTEmisor'=>$RutEmisor_esperado, 'RUTRecep'=>$RutReceptor_esperado]);
            $RecepcionDTE[] = [
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
        $estado = $EnvioDte->getEstadoValidacion(['RutReceptor'=>$RutReceptor_esperado]);
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
            'NroDTE' => count($RecepcionDTE),
            'RecepcionDTE' => $RecepcionDTE,
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
            return $xml;
        }

        return false;
    }
}

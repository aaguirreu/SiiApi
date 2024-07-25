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
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Webklex\PHPIMAP\Attachment;

class FacturaController extends DteController
{
    public function __construct($tipos_dte)
    {
        self::$tipos_dte = $tipos_dte;
    }

    /**
     * Enviar DTE al SII
     */
    public function enviar($dte, $rutEnvia, $rutEmisor, ?string $rutReceptor) {
        // definir datos que se usarán en el envío
        list($rutSender, $dvSender) = explode('-', str_replace('.', '', $rutEnvia));
        list($rutCompany, $dvCompany) = explode('-', str_replace('.', '', $rutEmisor));
        if (!str_contains($dte, '<?xml')) {
            $dte = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $dte;
        }

        $filename = $this->parseFileName($dte);
        try {
            $tmp_dir = TemporaryDirectory::make()->deleteWhenDestroyed();
            $xml_path = $tmp_dir->path($filename);
            file_put_contents($xml_path, $dte);
        } catch (Exception $e) {
            Log::write("Error al guardar dte en Storage.");
            Log::write($e->getMessage());
        }

        $data = [
            'rutSender' => $rutSender,
            'dvSender' => $dvSender,
            'rutCompany' => $rutCompany,
            'dvCompany' => $dvCompany,
            'archivo' => new CURLFile($xml_path, 'text/xml', $filename),
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
            return false;
        }

        // cerrar sesión curl
        curl_close($curl);

        // crear XML con la respuesta y retornar
        try {
            $xml = new \SimpleXMLElement($response, LIBXML_COMPACT);
        } catch (Exception $e) {
            \sasco\LibreDTE\Log::write(Estado::ENVIO_ERROR_XML, Estado::get(Estado::ENVIO_ERROR_XML, $e->getMessage()));
            return false;
        }

        // Verificar si el envío fue correcto
        if ($xml->STATUS!=0) {
            \sasco\LibreDTE\Log::write(
                $xml->STATUS,
                Estado::get($xml->STATUS).(isset($xml->DETAIL)?'. '.implode("\n", (array)$xml->DETAIL->ERROR):'')
            );
            return false;
        }

        // Convertir a array asociativo
        $arrayData = json_decode(json_encode($xml), true);

        // Respuesta como JSON
        $json_response = json_decode(json_encode($arrayData, JSON_PRETTY_PRINT));

        return [$json_response, $filename];
    }


}

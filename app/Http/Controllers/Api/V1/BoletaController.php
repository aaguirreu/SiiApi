<?php

namespace App\Http\Controllers\Api\V1;

use CURLFile;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use sasco\LibreDTE\Estado;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii\ConsumoFolio;
use sasco\LibreDTE\Sii\EnvioDte;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class BoletaController extends DteController
{
    public function __construct($tipos_dte)
    {
        self::$tipos_dte = $tipos_dte;
    }
    public function enviar($dte, $rut_envia, $rut_emisor, ?string $rutReceptor): bool|array
    {
        // definir datos que se usarán en el envío
        list($rutSender, $dvSender) = explode('-', str_replace('.', '', $rut_envia));
        list($rutCompany, $dvCompany) = explode('-', str_replace('.', '', $rut_emisor));
        if (strpos($dte, '<?xml') === false) {
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
        $header = ['Cookie: TOKEN=' . self::$token];

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

        // cerrar sesión curl
        curl_close($curl);
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

        // crear json con la respuesta y retornar
        try {
            $json_response = json_decode($response);
        } catch (Exception $e) {
            Log::write(Estado::ENVIO_ERROR_XML, Estado::get(Estado::ENVIO_ERROR_XML, $e->getMessage()));
            return false;
        }

        if (gettype($json_response) != 'object') {
            Log::write(
                Estado::ENVIO_ERROR_XML,
                Estado::get(Estado::ENVIO_ERROR_XML, $response)
            );
            return false;
        }

        return [$json_response, $filename];
    }

}

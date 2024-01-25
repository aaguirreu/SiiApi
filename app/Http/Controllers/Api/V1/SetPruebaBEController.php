<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use CURLFile;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use sasco\LibreDTE\Estado;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\Sii\Autenticacion;
use sasco\LibreDTE\Sii\ConsumoFolio;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\Sii\Folios;
use sasco\LibreDTE\XML;
use SimpleXMLElement;

class SetPruebaBEController extends DteController
{
    // Número de casos, solo para set de pruebas
    private static $casos;

    public function __construct($tipos_dte)
    {
        self::$tipos_dte = $tipos_dte;
    }

    protected function enviar($usuario, $empresa, $dte)
    {
        $token = json_decode(file_get_contents(base_path('config.json')))->tokenBE;
        // definir datos que se usarán en el envío
        list($rutSender, $dvSender) = explode('-', str_replace('.', '', $usuario));
        list($rutCompany, $dvCompany) = explode('-', str_replace('.', '', $empresa));
        if (strpos($dte, '<?xml') === false) {
            $dte = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $dte;
        }
        do {
            if (!file_exists(env('DTES_PATH', "") . "EnvioBOLETA")) {
                mkdir(env('DTES_PATH', "") . "EnvioBOLETA", 0777, true);
            }
            $filename = 'EnvioBOLETA_' . $this->timestamp . '.xml';
            $filename = str_replace(' ', 'T', $filename);
            $filename = str_replace(':', '-', $filename);
            $file = env('DTES_PATH', "") . "EnvioBOLETA\\" . $filename;
        } while (file_exists($file));
        Storage::disk('xml')->put('EnvioBOLETA\\' . $filename, $dte);
        $data = [
            'rutSender' => $rutSender,
            'dvSender' => $dvSender,
            'rutCompany' => $rutCompany,
            'dvCompany' => $dvCompany,
            'archivo' => new CURLFile($file),
        ];
        $header = ['Cookie: TOKEN=' . $token];

        // crear sesión curl con sus opciones
        $curl = curl_init();
        $url = self::$url;
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        // enviar XML al SII
        for ($i = 0; $i < self::$retry; $i++) {
            $response = curl_exec($curl);
            if ($response and $response != 'Error 500') {
                break;
            }
        }

        // cerrar sesión curl
        curl_close($curl);

        // verificar respuesta del envío y entregar error en caso que haya uno
        if (!$response or $response == 'Error 500') {
            if (!$response) {
                Log::write(Estado::ENVIO_ERROR_CURL, Estado::get(Estado::ENVIO_ERROR_CURL, curl_error($curl)));
            }
            if ($response == 'Error 500') {
                Log::write(Estado::ENVIO_ERROR_500, Estado::get(Estado::ENVIO_ERROR_500));
            }
            // Borrar xml guardado anteriormente
            Storage::disk('xml')->delete('EnvioBOLETA\\' . $filename);
            return response()->json([
                'message' => 'Error al enviar el DTE al SII',
                'error' => $response,
            ], 400);
        }

        // crear json con la respuesta y retornar
        try {
            $json_response = json_decode($response);
        } catch (Exception $e) {
            Log::write(Estado::ENVIO_ERROR_XML, Estado::get(Estado::ENVIO_ERROR_XML, $e->getMessage()));
            echo $e;
            return false;
        }
        if (gettype($json_response) != 'object') {
            echo $response;
            Log::write(
                Estado::ENVIO_ERROR_XML,
                Estado::get(Estado::ENVIO_ERROR_XML, $response)
            );
        }

        // Guardar envio dte en la base de datos
        $this->guardarEnvioDte($json_response, $filename);

        return $response;
    }

    protected function parseDte($dte): array
    {
        // Contador Casos (número de documentos a enviar)
        // SOLO PARA SET DE PRUEBAS
        self::$casos = 1;

        $boletas = [];
        foreach ($dte->Boletas as $boleta) {
            // Modelo boleta
            $modeloBoleta = [
                "Encabezado" => [
                    "IdDoc" => [],
                    "Emisor" => [
                        'RUTEmisor' => $boleta->Encabezado->Emisor->RUTEmisor ?? false,
                        'RznSoc' => $boleta->Encabezado->Emisor->RznSoc ?? false,
                        'GiroEmis' => $boleta->Encabezado->Emisor->GiroEmis ?? false,
                        'DirOrigen' => $boleta->Encabezado->Emisor->DirOrigen ?? false,
                        'CmnaOrigen' => $boleta->Encabezado->Emisor->CmnaOrigen ?? false,
                    ],
                    "Receptor" => [
                        'RUTRecep' => $boleta->Encabezado->Receptor->RUTRecep ?? false,
                        'RznSocRecep' => $boleta->Encabezado->Receptor->RznSocRecep ?? false,
                        'DirRecep' => $boleta->Encabezado->Receptor->DirRecep ?? false,
                        'CmnaRecep' => $boleta->Encabezado->Receptor->CmnaRecep ?? false,
                    ],
                ],
                "Detalle" => [],
                "Referencia" => [],
            ];

            $detallesExentos = [];
            $detallesAfectos = [];

            foreach ($boleta->Detalle as $detalle) {
                if (array_key_exists("IndExe", json_decode(json_encode($detalle), true))) {
                    $detallesExentos[] = json_decode(json_encode($detalle), true);
                } else {
                    $detallesAfectos[] = json_decode(json_encode($detalle), true);
                }
            }

            if (!empty($detallesExentos)) {
                $modeloBoletaExenta = $this->generarModeloBoleta($modeloBoleta, $detallesExentos, 41);
                $boletas[] = $modeloBoletaExenta;
            }

            if (!empty($detallesAfectos)) {
                $modeloBoletaAfecta = $this->generarModeloBoleta($modeloBoleta, $detallesAfectos, 39);
                $boletas[] = $modeloBoletaAfecta;
            }
            self::$casos++;
        }

        // Compara si el número de folios restante en el caf es mayor o igual al número de documentos a enviar
        foreach (self::$tipos_dte as $key) {
            $folios_restantes = DB::table('caf')->where('folio_id', '=', $key)->latest()->first()->folio_final - DB::table('folio')->where('id', '=', $key)->latest()->first()->cant_folios;
            $folios_boletas = self::$folios[$key] - self::$folios_inicial[$key] + 1;
            if ($folios_boletas > $folios_restantes) {
                $response[] = [
                    'error' => 'No hay folios suficientes para generar los documentos',
                    'tipo_folio' => $key,
                    'folios_restantes' => $folios_restantes,
                    'folios_boletas' => $folios_boletas,
                ];
            }
        }

        return $response ?? $boletas;
    }

    protected function generarModeloBoleta($modeloBoleta, $detalles, $tipoDTE): array
    {
        $modeloBoleta["Encabezado"]["IdDoc"] = [
            "TipoDTE" => $tipoDTE,
            "Folio" => ++self::$folios[$tipoDTE],
        ];
        $modeloBoleta["Detalle"] = $detalles;
        $modeloBoleta["Referencia"] = [
            'TpoDocRef' => 'SET', // 'SET' solo para set de pruebas, debe ser = $tipoDTE para dte que no son de prueba
            'FolioRef' => self::$folios[$tipoDTE],
            'RazonRef' => 'CASO-' . self::$casos,
        ];
        return $modeloBoleta;
    }
}

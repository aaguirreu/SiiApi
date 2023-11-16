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

class BoletaController extends DteController
{
    public function __construct($tipos_dte, $url, $ambiente)
    {
        self::$tipos_dte = $tipos_dte;
        self::$url = $url;
        self::$ambiente = $ambiente;
    }
    protected function enviar($usuario, $empresa, $dte)
    {
        $token = json_decode(file_get_contents(base_path('config.json')))->token_dte;
        // definir datos que se usarán en el envío
        list($rutSender, $dvSender) = explode('-', str_replace('.', '', $usuario));
        list($rutCompany, $dvCompany) = explode('-', str_replace('.', '', $empresa));
        if (strpos($dte, '<?xml') === false) {
            $dte = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $dte;
        }
        do {
            if (!file_exists(env('DTES_PATH', "")."EnvioBOLETA")) {
                mkdir(env('DTES_PATH', "")."EnvioBOLETA", 0777, true);
            }
            $filename = 'EnvioBOLETA_'.$this->timestamp.'.xml';
            $filename = str_replace(' ', 'T', $filename);
            $filename = str_replace(':', '-', $filename);
            $file = env('DTES_PATH', "")."EnvioBOLETA\\".$filename;
        } while (file_exists($file));
        Storage::disk('dtes')->put('EnvioBOLETA\\'.$filename, $dte);
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
        //$url = 'https://rahue.sii.cl/recursos/v1/boleta.electronica.envio'; // producción
        $url = 'https://pangal.sii.cl/recursos/v1/boleta.electronica.envio'; // certificación
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_URL, $url);
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
            if ($response=='Error 500') {
                Log::write(Estado::ENVIO_ERROR_500, Estado::get(Estado::ENVIO_ERROR_500));
            }
            // Borrar xml guardado anteriormente
            Storage::disk('dtes')->delete('EnvioBOLETA\\'.$filename);
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

    protected function generarRCOF($documentos)
    {
        // cargar XML boletas y notas
        $EnvioBOLETA = new EnvioDte();
        // podría ser un arrai de EnvioBoleta
        $EnvioBOLETA->loadXML($documentos);

        // crear objeto para consumo de folios
        $ConsumoFolio = new ConsumoFolio();
        $ConsumoFolio->setFirma($this->obtenerFirma());
        $ConsumoFolio->setDocumentos([39, 41]); // [39, 61] si es sólo afecto, [41, 61] si es sólo exento

        // agregar detalle de boletas
        // Se puede recorrer un array de EnvioDTE
        foreach ($EnvioBOLETA->getDocumentos() as $Dte) {
            $ConsumoFolio->agregar($Dte->getResumen());
        }

        // crear carátula para el envío (se hace después de agregar los detalles ya que
        // así se obtiene automáticamente la fecha inicial y final de los documentos)
        $CaratulaEnvioBOLETA = $EnvioBOLETA->getCaratula();
        $ConsumoFolio->setCaratula([
            'RutEmisor' => $CaratulaEnvioBOLETA['RutEmisor'],
            'FchResol' => $CaratulaEnvioBOLETA['FchResol'],
            'NroResol' => $CaratulaEnvioBOLETA['NroResol'],
        ]);

        // generar y validar schema
        $ConsumoFolio->generar();
        if (!$ConsumoFolio->schemaValidate()) {
            // si hubo errores mostrar
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return $errores;
        }
        return $ConsumoFolio;
    }

    protected function enviarRcof(ConsumoFolio $ConsumoFolio, $dte_filename) {
        // Set ambiente certificacion
        Sii::setAmbiente(Sii::PRODUCCION);

        // Enviar rcof
        $response = $ConsumoFolio->enviar(self::$retry);

        if($response != false){
            // Guardar xml en storage
            $filename = 'RCOF_'.$this->timestamp.'.xml';
            $filename = str_replace(' ', 'T', $filename);
            $filename = str_replace(':', '-', $filename);
            //$file = env('DTES_PATH', "")."EnvioBOLETA/".$filename;
            Storage::disk('dtes')->put('EnvioRcof/'.$filename, $ConsumoFolio->generar());

            $dte_id = DB::table('dte')->where('xml_filename', '=', $dte_filename)->latest()->first()->id;
            // Guardar en base de datos
            DB::table('resumen_ventas_diarias')->insert([
                'id' => $dte_id,
                'trackid' => $response,
                'xml_filename' => $filename,
                'created_at' => $this->timestamp,
                'updated_at' => $this->timestamp
            ]);
        }
        return $response;
    }
}

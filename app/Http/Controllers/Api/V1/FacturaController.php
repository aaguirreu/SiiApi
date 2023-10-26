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

class FacturaController extends DteController
{
    public function __construct($tipos_dte)
    {
        self::$tipos_dte = $tipos_dte;
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
            if (!file_exists(env('DTES_PATH', "")."EnvioFACTURA")) {
                mkdir(env('DTES_PATH', "")."EnvioFACTURA", 0777, true);
            }
            $filename = 'EnvioFACTURA_'.$this->timestamp.'.xml';
            $filename = str_replace(' ', 'T', $filename);
            $filename = str_replace(':', '-', $filename);
            $file = env('DTES_PATH', "")."EnvioFACTURA\\".$filename;
        } while (file_exists($file));

        // NO GUARDA LOS ARCHIVOS A PESAR DE QUE RETORNA QUE SI
        // Guardar xml en disco
        //$dte = mb_convert_encoding($dte, "UTF-8", "auto");

        if(!Storage::disk('dtes')->put('EnvioFACTURA\\'.$filename, $dte)){
            return response()->json([
                'message' => 'Error al guardar el DTE en el servidor',
            ], 400);
        }

        //$file = Storage::disk('dtes')->get('EnvioFACTURA\\'.$filename);
        //$file = mb_convert_encoding($filename, "ISO-8859-1", "auto");

        //$file= 'C:\Users\aagui\Downloads\factura_ejemplo.xml';

        $data = [
            'rutSender' => $rutSender,
            'dvSender' => $dvSender,
            'rutCompany' => $rutCompany,
            'dvCompany' => $dvCompany,
            'archivo' => new CURLFile($file, 'text/xml', $filename),
        ];

        $header = [
            'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Logiciel)',
            'Cookie: TOKEN=' . $token,
            'Content-Type: text/html; charset=ISO-8859-1',
        ];

        // crear sesión curl con sus opciones
        $curl = curl_init();
        //$url = 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload'; // certificacion
        $url = 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload'; // producción
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

        // verificar respuesta del envío y entregar error en caso que haya uno
        if (!$response or $response=='Error 500') {
            if (!$response) {
                Log::write(Estado::ENVIO_ERROR_CURL, Estado::get(Estado::ENVIO_ERROR_CURL, curl_error($curl)));
            }
            if ($response=='Error 500') {
                Log::write(Estado::ENVIO_ERROR_500, Estado::get(Estado::ENVIO_ERROR_500));
            }
            // Borrar xml guardado anteriormente
            Storage::disk('dtes')->delete('EnvioFACTURA\\'.$filename);
            return response()->json([
                'message' => 'Error al enviar el DTE al SII',
                'error' => $response,
            ], 400);
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
            return json_decode(json_encode($arrayData, JSON_PRETTY_PRINT));
        }

         // Convertir a array asociativo
        $arrayData = json_decode(json_encode($xml), true);

        // Respuesta como JSON
        $json_response = json_decode(json_encode($arrayData, JSON_PRETTY_PRINT));

        // Guardar envio dte en la base de datos
        $this->guardarEnvioDte($json_response, $filename);

        return $json_response;
    }

    // Borrar cuando deje de utilizar ambiente certificacion
    protected function getTokenDte()
    {
        // Set ambiente producción
        //Sii::setAmbiente(Sii::PRODUCCION);
        $token = Autenticacion::getToken($this->obtenerFirma());
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $config_file->token_dte = $token;
        $config_file->token_dte_timestamp = Carbon::now('America/Santiago')->timestamp;;
        //Storage::disk('local')->put('config.json', json_encode($config_file, JSON_PRETTY_PRINT));
        file_put_contents(base_path('config.json'), json_encode($config_file), JSON_PRETTY_PRINT);
    }

    protected function generarEnvioDteXml(array $factura, FirmaElectronica $Firma, array $Folios, array $caratula)
    {
        /*
        // caratula para el envío de los dte
        $caratula = [
            'RutEnvia' => '8782294-K',
            'RutReceptor' => '72095000-6',
            'FchResol' => '2014-08-22',
            'NroResol' => 0,
        ];

        // datos del emisor
        $Emisor = [
            'RUTEmisor' => '76974300-6',
            'RznSoc' => 'Logiciel Chile S.A.',
            'GiroEmis' => 'CONSULTORIAS, ASESORIAS, SERVICIOS DE INGENIERIA Y TELECOMUNICACIONES EXPORTACIO',
            'Acteco' => "620100,620200,711002,474100",
            'DirOrigen' => 'Av. Pedro de Valdivia 5841',
            'CmnaOrigen' => 'Macul',
        ];
        $folio_inicial = 51;
        $factura = [
            // CASO 414175-1
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 33,
                        'Folio' => $folio_inicial,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => [
                        'RUTRecep' => '55666777-8',
                        'RznSocRecep' => 'Empresa S.A.',
                        'GiroRecep' => 'Servicios jurídicos',
                        'DirRecep' => 'Santiago',
                        'CmnaRecep' => 'Santiago',
                    ],
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'Cajón AFECTO',
                        'QtyItem' => 123,
                        'PrcItem' => 923,
                    ],
                    [
                        'NmbItem' => 'Relleno AFECTO',
                        'QtyItem' => 53,
                        'PrcItem' => 1473,
                    ],
                ],
                'Referencia' => [
                    'TpoDocRef' => 'SET',
                    'FolioRef' => $folio_inicial,
                    'RazonRef' => 'CASO 414175-1',
                ],
            ],
            // CASO 414175-2
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 33,
                        'Folio' => $folio_inicial+1,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => [
                        'RUTRecep' => '55666777-8',
                        'RznSocRecep' => 'Empresa S.A.',
                        'GiroRecep' => 'Servicios jurídicos',
                        'DirRecep' => 'Santiago',
                        'CmnaRecep' => 'Santiago',
                    ],
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'Pañuelo AFECTO',
                        'QtyItem' => 235,
                        'PrcItem' => 1926,
                        'DescuentoPct' => 4,
                    ],
                    [
                        'NmbItem' => 'ITEM 2 AFECTO',
                        'QtyItem' => 161,
                        'PrcItem' => 990,
                        'DescuentoPct' => 5,
                    ],
                ],
                'Referencia' => [
                    'TpoDocRef' => 'SET',
                    'FolioRef' => $folio_inicial+1,
                    'RazonRef' => 'CASO 414175-2',
                ],
            ],
            // CASO 414175-3
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 33,
                        'Folio' => $folio_inicial+2,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => [
                        'RUTRecep' => '55666777-8',
                        'RznSocRecep' => 'Empresa S.A.',
                        'GiroRecep' => 'Servicios jurídicos',
                        'DirRecep' => 'Santiago',
                        'CmnaRecep' => 'Santiago',
                    ],
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'Pintura B&W AFECTO',
                        'QtyItem' => 24,
                        'PrcItem' => 1937,
                    ],
                    [
                        'NmbItem' => 'ITEM 2 AFECTO',
                        'QtyItem' => 149,
                        'PrcItem' => 2975,
                    ],
                    [
                        'IndExe' => 1,
                        'NmbItem' => 'ITEM 3 SERVICIO EXENTO',
                        'QtyItem' => 1,
                        'PrcItem' => 34705,
                    ],
                ],
                'Referencia' => [
                    'TpoDocRef' => 'SET',
                    'FolioRef' => $folio_inicial+2,
                    'RazonRef' => 'CASO 414175-3',
                ],
            ],
            // CASO 414175-4
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 33,
                        'Folio' => $folio_inicial+3,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => [
                        'RUTRecep' => '55666777-8',
                        'RznSocRecep' => 'Empresa S.A.',
                        'GiroRecep' => 'Servicios jurídicos',
                        'DirRecep' => 'Santiago',
                        'CmnaRecep' => 'Santiago',
                    ],
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'ITEM 1 AFECTO',
                        'QtyItem' => 81,
                        'PrcItem' => 1672,
                    ],
                    [
                        'NmbItem' => 'ITEM 2 AFECTO',
                        'QtyItem' => 35,
                        'PrcItem' => 1405,
                    ],
                    [
                        'IndExe' => 1,
                        'NmbItem' => 'ITEM 3 SERVICIO EXENTO',
                        'QtyItem' => 2,
                        'PrcItem' => 6767,
                    ],
                ],
                'DscRcgGlobal' => [
                    'TpoMov' => 'D',
                    'TpoValor' => '%',
                    'ValorDR' => 6,
                ],
                'Referencia' => [
                    'TpoDocRef' => 'SET',
                    'FolioRef' => $folio_inicial+3,
                    'RazonRef' => 'CASO 414175-4',
                ],
            ]
        ];*/

        // generar XML del DTE timbrado y firmado
        $EnvioDTE = new EnvioDte();
        foreach ($factura as $documento) {
            //$DTE = new Dte($documento, false); // Normalizar false
            $DTE = new Dte($documento, true); // Normalizar true (default)
            if (!$DTE->timbrar($Folios[$DTE->getTipo()]))
                break;
            if (!$DTE->firmar($Firma))
                break;
            $EnvioDTE->agregar($DTE);
        }
        // generar sobre con el envío del DTE y enviar al SII
        $EnvioDTE->setCaratula($caratula);
        $EnvioDTE->setFirma($Firma);
        $EnvioDTExml = $EnvioDTE->generar();
        if ($EnvioDTE->schemaValidate()) {
            return $EnvioDTExml;
        } else {
            //return $EnvioDTExml;
            // si hubo errores mostrar
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return $errores;
        }
    }

    protected function parseDte($dte): array
    {
        $boletas = [];
        $dte = json_decode(json_encode($dte), true);

        foreach ($dte["Boletas"] as $boleta) {
            // Modelo boleta
            /*
            $modeloBoleta = [
                "Encabezado" => [
                    "IdDoc" => $boleta["Encabezado"]["IdDoc"] ?? [],
                    "Emisor" => [
                        'RUTEmisor' => $boleta->Encabezado->Emisor->RUTEmisor ?? false,
                        'RznSoc' => $boleta->Encabezado->Emisor->RznSoc ?? false,
                        'GiroEmis' => $boleta->Encabezado->Emisor->GiroEmis ?? false,
                        'Acteco' => $boleta->Encabezado->Emisor->Acteco ?? false,
                        'DirOrigen' => $boleta->Encabezado->Emisor->DirOrigen ?? false,
                        'CmnaOrigen' => $boleta->Encabezado->Emisor->CmnaOrigen ?? false,
                        'CiudadOrigen' => $boleta->Encabezado->Emisor->CiudadOrigen ?? false,
                        'CdgVendedor' => $boleta->Encabezado->Emisor->CdgVendedor ?? false,
                    ],
                    "Receptor" => [
                        'RUTRecep' => $boleta->Encabezado->Receptor->RUTRecep ?? false,
                        'RznSocRecep' => $boleta->Encabezado->Receptor->RznSocRecep ?? false,
                        'GiroRecep' => $boleta->Encabezado->Receptor->GiroRecep ?? false,
                        'DirRecep' => $boleta->Encabezado->Receptor->DirRecep ?? false,
                        'CmnaRecep' => $boleta->Encabezado->Receptor->CmnaRecep ?? false,
                        'CiudadRecep' => $boleta->Encabezado->Receptor->CiudadRecep ?? false,
                    ],
                ],
                "Detalle" => [],
                //"Referencia" => [],
            ];*/

            $modeloBoleta = [
                "Encabezado" => [
                    "IdDoc" => $boleta["Encabezado"]["IdDoc"] ?? [],
                    "Emisor" => $boleta["Encabezado"]["Emisor"] ?? [],
                    "Receptor" => $boleta["Encabezado"]["Receptor"] ?? [],
                    ],
                "Detalle" => [],
                "Referencia" => $boleta["Referencia"] ?? false,
            ];

            $detallesExentos = [];
            $detallesAfectos = [];
            foreach ($boleta["Detalle"] as $detalle) {
                if (array_key_exists("IndExe", $detalle)) {
                    $detallesExentos[] = $detalle;
                } else {
                    $detallesAfectos[] = $detalle;
                }
            }

            if (!empty($detallesExentos)) {
                $modeloBoletaExenta = $this->generarModeloBoleta($modeloBoleta, $detallesExentos, 34);
                $boletas[] = $modeloBoletaExenta;
            }

            if (!empty($detallesAfectos)) {
                $modeloBoletaAfecta = $this->generarModeloBoleta($modeloBoleta, $detallesAfectos, 33);
                $boletas[] = $modeloBoletaAfecta;
            }
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

    protected function obtenerCaratula($dte): array
    {
        return [
            'RutEnvia' => $dte->Caratula->RutEnvia, // se obtiene automáticamente de la firma
            'RutReceptor' => $dte->Caratula->RutReceptor ?? false, // se obtiene automáticamente
            'FchResol' => $dte->Caratula->FchResol,
            'NroResol' => $dte->Caratula->NroResol,
        ];
    }
}



<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use CURLFile;
use Illuminate\Http\Request;
use sasco\LibreDTE\Estado;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\Sii\Autenticacion;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\Sii\Folios;
use sasco\LibreDTE\XML;
use SimpleXMLElement;
use Swaggest\JsonSchema\Schema;
use function Symfony\Component\String\b;

class BoletaController extends Controller
{
    public function index(Request $request) {
        // Leer string como json
        $rbody = json_encode($request->json()->all());

        // Transformar a json
        $json = json_decode($rbody);

        // Schema del json
        $schemaJson = file_get_contents(base_path().'\SchemasSwagger\SchemaBoleta.json');

        // Validar json
        //$schema = Schema::import(json_decode($schemaJson));
        //$schema->in($json));

        //$jsonArr = var_dump($json);
        //$this->setPruebas($json);
        return $this->setPruebas($json);
    }

    public function setPruebas($dte) {
        // Firma .p12
        $config = [
            'firma' => [
                'file' => env("CERT_PATH", ""),
                //'data' => '', // contenido del archivo certificado.p12
                'pass' => env("CERT_PASS", "")
            ],
        ];

        // Contador Casos (número de documentos a enviar)
        // SOLO PARA SET DE PRUEBAS
        $casos = 1;


        // Primer folio a usar para envio de set de pruebas
        $folios = [
            39 => 1, // boleta electrónica, es igual al último folio
            41 => 1, // boleta afecta o exenta electrónica, es igual al último folio
        ];

        // Obtener caratula

        $caratula = [
            //'RutEnvia' => '11222333-4', // se obtiene automáticamente de la firma
            'RutReceptor' => $dte->Caratula->RutReceptor,
            'FchResol' => $dte->Caratula->FchResol,
            'NroResol' => $dte->Caratula->NroResol,
        ];

        // Parseo de boletas según modelo libreDTE
        $boletas = [];
        foreach ($dte->Boletas as $boleta) {
            // Modelo boleta
            $modeloBoleta = [
                "Encabezado" => [
                    "IdDoc" => [],
                    "Emisor" => [
                        'RUTEmisor' => $boleta->Encabezado->Emisor->RUTEmisor,
                        'RznSoc' => $boleta->Encabezado->Emisor->RznSoc,
                        'GiroEmis' => $boleta->Encabezado->Emisor->GiroEmis,
                        'DirOrigen' => $boleta->Encabezado->Emisor->DirOrigen,
                        'CmnaOrigen' => $boleta->Encabezado->Emisor->CmnaOrigen,
                    ],
                    "Receptor" => [
                        'RUTRecep' => $boleta->Encabezado->Receptor->RUTRecep,
                        'RznSocRecep' => $boleta->Encabezado->Receptor->RznSocRecep,
                        'DirRecep' => $boleta->Encabezado->Receptor->DirRecep,
                        'CmnaRecep' => $boleta->Encabezado->Receptor->CmnaRecep,
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
                $modeloBoletaExenta = $this->generarModeloBoleta($modeloBoleta, $detallesExentos, $folios, 41, $casos);
                $boletas[] = $modeloBoletaExenta;
                $folios[41]++;
            }

            if (!empty($detallesAfectos)) {
                $modeloBoletaAfecta = $this->generarModeloBoleta($modeloBoleta, $detallesAfectos, $folios, 39, $casos);
                $boletas[] = $modeloBoletaAfecta;
                $folios[39]++;
            }
            $casos++;
        }

        // Objetos de Firma y Folios
        $Firma = new FirmaElectronica($config['firma']);
        $Folios = [];
        foreach ($folios as $tipo => $cantidad)
        $Folios[$tipo] = new Folios(file_get_contents(env("FOLIOS_PATH", "").$tipo.'.xml'));

        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $EnvioDTE = new EnvioDte();
        foreach ($boletas as $documento) {
            $DTE = new Dte($documento);
            if (!$DTE->timbrar($Folios[$DTE->getTipo()]))
            break;
            if (!$DTE->firmar($Firma))
            break;
            $EnvioDTE->agregar($DTE);
        }
        $EnvioDTE->setFirma($Firma);
        $EnvioDTE->setCaratula($caratula);
        $EnvioDTE->generar();
        $EnvioDTExml = new XML();
        if ($EnvioDTE->schemaValidate()) {
            $EnvioDTExml = $EnvioDTE->generar();
        }

        // si hubo errores mostrar
        foreach (Log::readAll() as $error)
            echo $error,"\n";

        //$token = 'T34GJ7Q96N5VN';
        $token = $this->getToken($Firma);

        // Enviar DTE
        $RutEnvia = $Firma->getID(); // RUT autorizado para enviar DTEs
        $RutEmisor = $boletas[0]['Encabezado']['Emisor']['RUTEmisor']; // RUT del emisor del DTE
        $response = $this->enviar($RutEnvia, $RutEmisor, $EnvioDTExml, $token);
        echo $response;
        /*
        // Si hubo algún error al enviar al servidor mostrar
        if ($result===false) {
            foreach (Log::readAll() as $error)
                echo $error,"\n";
                exit;
        }

        // Mostrar resultado del envío
        if ($result->STATUS!='0') {
            foreach (Log::readAll() as $error)
            echo $error,"\n";
            exit;
        }
        echo $result;
        //echo 'DTE envíado. Track ID '.$result->TRACKID,"\n";
        */
    }

    public function estadoDteEnviado(Request $request)
    {
        // Leer string como json
        $rbody = json_encode($request->json()->all());

        // Transformar a json
        $body = json_decode($rbody);

        // Schema del json
        //$schemaJson = file_get_contents(base_path().'\SchemasSwagger\SchemaStatusBE.json');

        // Validar json
        //$schema = Schema::import(json_decode($schemaJson));
        //$schema->in($body);

        // Firma .p12
        $config = [
            'firma' => [
                'file' => env("CERT_PATH", ""),
                //'data' => '', // contenido del archivo certificado.p12
                'pass' => env("CERT_PASS", "")
            ],
        ];
        // solicitar token
        /*
        $token = Autenticacion::getToken($config['firma']);

        if (!$token) {
            foreach (Log::readAll() as $error)
                echo $error,"\n";
            exit;
        }*/
        $token = 'N503ED2YIRPBA';
        // consultar estado dte
        $rut = $body->rut;
        $dv = $body->dv;
        $trackID = $body->trackID;
        $destino = $rut.'-'.$dv.'-'.$trackID;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.envio/'.$destino,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Cookie: TOKEN='.$token.'\n',
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        echo $response;
    }

    public function estadoDte(Request $request)
    {
        // Leer string como json
        $rbody = json_encode($request->json()->all());

        // Transformar a json
        $body = json_decode($rbody);

        // Schema del json
        //$schemaJson = file_get_contents(base_path().'\SchemasSwagger\SchemaStatusBE.json');

        // Validar json
        //$schema = Schema::import(json_decode($schemaJson));
        //$schema->in($body);

        // Firma .p12
        $config = [
            'firma' => [
                'file' => env("CERT_PATH", ""),
                //'data' => '', // contenido del archivo certificado.p12
                'pass' => env("CERT_PASS", "")
            ],
        ];
        // solicitar token
        /*$token = Autenticacion::getToken($config['firma']);

        if (!$token) {
            foreach (Log::readAll() as $error)
                echo $error,"\n";
            exit;
        }*/
        $token = 'T34GJ7Q96N5VN';

        // Consulta estado dte
        $rut = $body->rut;
        $dv = $body->dv;
        $tipo = $body->tipo;
        $folio = $body->folio;
        $rut_receptor = $body->rut_receptor;
        $dv_receptor = $body->dv_receptor;
        $monto = $body->monto;
        $fechaEmision = $body->fechaEmision;
        $required = $rut.'-'.$dv.'-'.$tipo.'-'.$folio;
        $opcionales = '?rut_receptor='.$rut_receptor.'&dv_receptor='.$dv_receptor.'&monto='.$monto.'&fechaEmision='.$fechaEmision;
        $url = 'https://apicert.sii.cl/recursos/v1/boleta.electronica/'.$required.'/estado'.$opcionales;
        echo $url."\n";
        $url = 'https://apicert.sii.cl/recursos/v1/boleta.electronica/76974300-6-39-1/estado?rut_receptor=19279633&dv_receptor=4&monto=29800&fechaEmision=2023-08-24%2017%3A14%3A08';
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
                'Cookie: TOKEN='.$token.'\n',
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        echo $response;
    }

    public function generarModeloBoleta($modeloBoleta, $detalles, $folio, $tipoDTE, $casos) {
        $modeloBoleta["Encabezado"]["IdDoc"] = [
            "TipoDTE" => $tipoDTE,
            "Folio" => $folio[$tipoDTE],
        ];
        $modeloBoleta["Detalle"] = $detalles;
        $modeloBoleta["Referencia"] = [
            'TpoDocRef' => 'SET', // 'SET' solo para set de pruebas, debe ser = $tipoDTE para dte que no son de prueba
            'FolioRef' => $folio[$tipoDTE],
            'RazonRef' => 'CASO-'.$casos,
        ];
        return $modeloBoleta;
    }
    public static function enviar($usuario, $empresa, $dte, $token)
    {
        // definir datos que se usarán en el envío
        list($rutSender, $dvSender) = explode('-', str_replace('.', '', $usuario));
        list($rutCompany, $dvCompany) = explode('-', str_replace('.', '', $empresa));
        if (strpos($dte, '<?xml') === false) {
            $dte = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $dte;
        }
        do {
            $file = sys_get_temp_dir() . '/dte_' . md5(microtime() . $token . $dte) . '.' . 'xml';
        } while (file_exists($file));
        file_put_contents($file, $dte);
        $data = [
            'rutSender' => $rutSender,
            'dvSender' => $dvSender,
            'rutCompany' => $rutCompany,
            'dvCompany' => $dvCompany,
            'archivo' => new CURLFILE($file),
        ];
        $header = ['Cookie: TOKEN=' . $token];

        // crear sesión curl con sus opciones
        $curl = curl_init();
        //$url = 'https://'.self::$config['servidor'][self::getAmbiente()].'.sii.cl/cgi_dte/UPL/DTEUpload';
        $url = 'https://pangal.sii.cl/recursos/v1/boleta.electronica.envio';
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        // si no se debe verificar el SSL se asigna opción a curl, además si
        // se está en el ambiente de producción y no se verifica SSL se
        // generará una entrada en el log
        // enviar XML al SII
        $response = curl_exec($curl);

        unlink($file);

        // cerrar sesión curl
        curl_close($curl);
        return $response;
    }
    public function getToken($Firma){
        // Solicitar seed
        $seed = file_get_contents('https://apicert.sii.cl/recursos/v1/boleta.electronica.semilla');

        // Solicitar token
        $seedSigned = $Firma->signXML(
            (new XML())->generate([
                'getToken' => [
                    'item' => [
                        'Semilla' => $seed
                    ]
                ]
            ])->saveXML()
        );

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>$seedSigned,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/xml',
                'Cookie: dtCookie=v_4_srv_44_sn_994889E1E44A72865299C3986D39696C_perc_100000_ol_0_mul_1_app-3Aea7c4b59f27d43eb_0'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);

        $responseXml = simplexml_load_string($response);

        // Retornar Token
        return (string) $responseXml->xpath('//TOKEN')[0];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\Sii\Autenticacion;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\Sii\Folios;
use sasco\LibreDTE\XML;
use SimpleXMLElement;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\DomCrawler\Crawler;

class DteController extends Controller
{
    protected string $timestamp;
    protected static int $retry = 10;
    protected static array $folios = [];
    protected static array $tipos_dte = [];
    protected static string $url = '';
    protected static string $url_api = ''; // se utiliza solo en boleta electronica para consultas de estado
    protected static int $ambiente = 0; // 0 Producción, 1 Certificación
    protected static string $token = '';
    protected static string $token_api; // se utiliza solo en boleta electronica para consultas de estado

    protected function getTokenBE($url, $Firma): void
    {
        // Solicitar seed
        $url_seed = $url.'.semilla';
        $seed = file_get_contents($url_seed);
        $seed = simplexml_load_string($seed);
        $seed = (string)$seed->xpath('//SII:RESP_BODY/SEMILLA')[0];
        //echo "Seed = ".$seed."\n";

        // Generar xml de semilla firmada
        $seedSigned = $Firma->signXML(
            (new XML("1.0", "UTF-8"))->generate([
                'getToken' => [
                    'item' => [
                        'Semilla' => $seed
                    ]
                ]
            ])->saveXML()
        );
        //echo $seedSigned."\n";

        // Solicitar token con la semilla firmada
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url.".token",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $seedSigned,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/xml',
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        //echo $response;
        $responseXml = simplexml_load_string($response);

        // Guardar Token con su timestamp en config.json
        $token = (string)$responseXml->xpath('//TOKEN')[0];
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $rut = $Firma->getID();
        if ($url == 'https://apicert.sii.cl/recursos/v1/boleta.electronica') {
            $config_file->$rut->be->cert->token = $token;
            $config_file->$rut->be->cert->token_timestamp = Carbon::now('America/Santiago')->timestamp;
        }
        else if ($url == 'https://api.sii.cl/recursos/v1/boleta.electronica') {
            $config_file->$rut->be->prod->token = $token;
            $config_file->$rut->be->prod->token_timestamp = Carbon::now('America/Santiago')->timestamp;
        }
        $this->guardarConfigFile($config_file);
    }

    protected function getToken($ambiente, FirmaElectronica $Firma): void
    {
        // Set ambiente
        Sii::setAmbiente($ambiente);
        $token = Autenticacion::getToken($Firma);
        $config_file = json_decode(file_get_contents(base_path('config.json')));
        $rut = $Firma->getID();
        if ($ambiente == SII::CERTIFICACION) {
            $config_file->$rut->dte->cert->token = $token;
            $config_file->$rut->dte->cert->token_timestamp = Carbon::now('America/Santiago')->timestamp;
        } else if ($ambiente == SII::PRODUCCION) {
            $config_file->$rut->dte->prod->token = $token;
            $config_file->$rut->dte->prod->token_timestamp = Carbon::now('America/Santiago')->timestamp;
        }
        $this->guardarConfigFile($config_file);
    }

    protected function isToken($rut_envia, $Firma): void
    {
        if (file_exists(base_path('config.json'))) {
            // Obtener config.json
            $config_file = json_decode(file_get_contents(base_path('config.json')));
            if (isset($config_file->$rut_envia)){
                // Verificar token Boleta Electronica
                if ($config_file->$rut_envia->be->cert->token === '' || !$config_file->$rut_envia->be->cert->token || !$config_file->$rut_envia->be->cert->token_timestamp)
                    // Obtener token de boleta electrónica certificación
                    $this->getTokenBE('https://apicert.sii.cl/recursos/v1/boleta.electronica', $Firma);
                else if ($config_file->$rut_envia->be->prod->token === '' || !$config_file->$rut_envia->be->prod->token || !$config_file->$rut_envia->be->prod->token_timestamp)
                    // Obtener token de boleta electrónica certificación y producción
                    $this->getTokenBE('https://api.sii.cl/recursos/v1/boleta.electronica', $Firma);
                else {
                    $now = Carbon::now('America/Santiago')->timestamp;
                    $diff = $now - $config_file->$rut_envia->be->cert->token_timestamp;
                    if ($diff > $config_file->$rut_envia->be->cert->token_expiration) {
                        $this->getTokenBE('https://apicert.sii.cl/recursos/v1/boleta.electronica', $Firma); // certificación
                    }
                    $diff = $now - $config_file->$rut_envia->be->prod->token_timestamp;
                    if ($diff > $config_file->$rut_envia->be->prod->token_expiration) {
                        $this->getTokenBE('https://api.sii.cl/recursos/v1/boleta.electronica', $Firma); // producción
                    }
                }

                // Verificar token DTE
                if ($config_file->$rut_envia->dte->cert->token === '' || !$config_file->$rut_envia->dte->cert->token || !$config_file->$rut_envia->dte->cert->token_timestamp)
                    $this->getToken(SII::CERTIFICACION, $Firma);
                else if ($config_file->$rut_envia->dte->prod->token === '' || !$config_file->$rut_envia->dte->prod->token || !$config_file->$rut_envia->dte->prod->token_timestamp)
                    $this->getToken(SII::PRODUCCION, $Firma);
                else {
                    $now = Carbon::now('America/Santiago')->timestamp;
                    $diff = $now - $config_file->$rut_envia->dte->cert->token_timestamp;
                    if ($diff > $config_file->$rut_envia->dte->cert->token_expiration) {
                        $this->getToken(SII::CERTIFICACION, $Firma);
                    }
                    $diff = $now - $config_file->$rut_envia->dte->prod->token_timestamp;
                    if ($diff > $config_file->$rut_envia->dte->prod->token_expiration) {
                        $this->getToken(SII::PRODUCCION, $Firma);
                    }
                }
            } else {
                $config_file = json_decode(file_get_contents(base_path('config.json')), true);
                $nuevo_rut = [
                    'dte' => [
                        'prod' => [
                            'token' => '',
                            'token_timestamp' => '',
                            'token_expiration' => 3600
                        ],
                        'cert' => [
                            'token' => '',
                            'token_timestamp' => '',
                            'token_expiration' => 3600
                        ]
                    ],
                    'be' => [
                        'prod' => [
                            'token' => '',
                            'token_timestamp' => '',
                            'token_expiration' => 3600
                        ],
                        'cert' => [
                            'token' => '',
                            'token_timestamp' => '',
                            'token_expiration' => 3600
                        ]
                    ]
                ];
                $config_file[$rut_envia] = $nuevo_rut;

                file_put_contents(base_path('config.json'), json_encode($config_file, JSON_PRETTY_PRINT));

                // Obtener token de boleta electrónica certificación y producción
                $this->getToken(SII::CERTIFICACION, $Firma);
                $this->getToken(SII::PRODUCCION, $Firma);

                $this->getTokenBE('https://apicert.sii.cl/recursos/v1/boleta.electronica', $Firma); // certificación
                $this->getTokenBE('https://api.sii.cl/recursos/v1/boleta.electronica', $Firma); // producción
            }
        } else {
            file_put_contents(base_path('config.json'), json_encode([
                "$rut_envia" => [
                    'dte' => [
                        'prod' => [
                            'token' => '',
                            'token_timestamp' => '',
                            'token_expiration' => 3600
                        ],
                        'cert' => [
                            'token' => '',
                            'token_timestamp' => '',
                            'token_expiration' => 3600
                        ]
                    ],
                    'be' => [
                        'prod' => [
                            'token' => '',
                            'token_timestamp' => '',
                            'token_expiration' => 3600
                        ],
                        'cert' => [
                            'token' => '',
                            'token_timestamp' => '',
                            'token_expiration' => 3600
                        ]
                    ]
                ]
            ], JSON_PRETTY_PRINT));

            // Obtener token de boleta electrónica certificación y producción
            $this->getToken(SII::CERTIFICACION, $Firma);
            $this->getToken(SII::PRODUCCION, $Firma);

            $this->getTokenBE('https://apicert.sii.cl/recursos/v1/boleta.electronica', $Firma); // certificación
            $this->getTokenBE('https://api.sii.cl/recursos/v1/boleta.electronica', $Firma); // producción
        }
    }

    protected function obtenerFirma($path, $password): FirmaElectronica
    {
        // Firma .pfx
        $config = [
            'firma' => [
                'file' => $path,
                //'data' => '', // contenido del archivo certificado.p12
                'pass' => $password
            ],
        ];
        return new FirmaElectronica($config['firma']);
    }

    protected function guardarConfigFile($config_file): void
    {
        file_put_contents(base_path('config.json'), json_encode($config_file,JSON_PRETTY_PRINT));
    }

    public function obtenerCaratula($dte, $documentos, FirmaElectronica $Firma): array
    {
        return [
            'RutEmisor' => $dte->Caratula->RutEmisor ?? $documentos[0]['Encabezado']['Emisor']['RUTEmisor'], // se obtiene automáticamente
            'RutEnvia' => $Firma->getID(),
            'RutReceptor' => $dte->Caratula->RutReceptor ?? "60803000-K",
            'FchResol' => $dte->Caratula->FchResol,
            'NroResol' => $dte->Caratula->NroResol,
        ];
    }

    public function generarEnvioDteXml(array $documentos, FirmaElectronica $Firma, array $folios, array $caratula): mixed
    {
        $EnvioDTE = new EnvioDte();
        foreach ($documentos as $documento) {
            $DTE = new Dte($documento);
            if (!$DTE->timbrar($folios[intval($DTE->getTipo())]))
                //if (!$DTE->timbrar($folios[$DTE->getTipo()]))
                break;
            if (!$DTE->firmar($Firma))
                break;
            $EnvioDTE->agregar($DTE);
        }
        $EnvioDTE->setFirma($Firma);
        $EnvioDTE->setCaratula($caratula);
        $EnvioDTE->generar();
        $errores = [];
        if ($EnvioDTE->schemaValidate()) {
            return $EnvioDTE->generar();
        } else {
            //return $EnvioDTExml = $EnvioDTE->generar();
            // si hubo errores mostrar
            foreach (Log::readAll() as $error)
                $errores[] = $error->msg;
            return $errores;
        }
    }

    protected function parseFileName($dte): string
    {
        $Xml = new SimpleXMLElement($dte);
        //$tipo_dte = key(array_filter(self::$folios));
        $tipo_dte = $Xml->children()->SetDTE->DTE->Documento[0]->Encabezado->TipoDTE;
        //$folio = self::$folios[$tipo_dte];
        $folio = $Xml->children()->SetDTE->DTE->Documento[0]->Encabezado->Folio;
        $filename = "DTE_$tipo_dte" . "_$folio" . "_$this->timestamp.xml";
        $filename = str_replace(' ', 'T', $filename);
        $filename = str_replace(':', '-', $filename);
        return $filename;
    }

    protected function getDataConfirmarFolio($rut_emp, $dv_emp, $tipo_folio, $cant_doctos, $html): array
    {
        // Obtener el contenido HTML de la respuesta
        $crawler = new Crawler($html);
        $max_autor = $crawler->filter('input[name="MAX_AUTOR"]')->attr('value');
        $afecto_iva = $crawler->filter('input[name="AFECTO_IVA"]')->attr('value');
        $anotacion = $crawler->filter('input[name="ANOTACION"]')->attr('value');
        $con_credito = $crawler->filter('input[name="CON_CREDITO"]')->attr('value');
        $con_ajuste = $crawler->filter('input[name="CON_AJUSTE"]')->attr('value');
        $factor = $crawler->filter('input[name="FACTOR"]')->attr('value');
        $ult_timbraje = $crawler->filter('input[name="ULT_TIMBRAJE"]')->attr('value');
        $con_historia = $crawler->filter('input[name="CON_HISTORIA"]')->attr('value');
        $folio_ini_cre = $crawler->filter('input[name="FOLIO_INICRE"]')->attr('value');
        $folio_fin_cre = $crawler->filter('input[name="FOLIO_FINCRE"]')->attr('value');
        $fecha_ant = $crawler->filter('input[name="FECHA_ANT"]')->attr('value');
        $estado_timbraje = $crawler->filter('input[name="ESTADO_TIMBRAJE"]')->attr('value');
        $control = $crawler->filter('input[name="CONTROL"]')->attr('value');
        $cant_timbrajes = $crawler->filter('input[name="CANT_TIMBRAJES"]')->attr('value');
        $folio_inicial = $crawler->filter('input[name="FOLIO_INICIAL"]')->attr('value');
        $folios_disp = $crawler->filter('input[name="FOLIOS_DISP"]')->attr('value');

        // Agregar el valor de 'MAX_AUTOR' al array de datos
        return [
            'RUT_EMP' => $rut_emp,
            'DV_EMP' => $dv_emp,
            'FOLIO_INICIAL' => $folio_inicial,
            'COD_DOCTO' => $tipo_folio,
            'AFECTO_IVA' => $afecto_iva,
            'ANOTACION' => $anotacion,
            'CON_CREDITO' => $con_credito,
            'CON_AJUSTE' => $con_ajuste,
            'FACTOR' => $factor,
            'MAX_AUTOR' => $max_autor,
            'ULT_TIMBRAJE' => $ult_timbraje,
            'CON_HISTORIA' => $con_historia,
            'FOLIO_INICRE' => $folio_ini_cre,
            'FOLIO_FINCRE' => $folio_fin_cre,
            'FECHA_ANT' => $fecha_ant,
            'ESTADO_TIMBRAJE' => $estado_timbraje,
            'CONTROL' => $control,
            'CANT_TIMBRAJES' => $cant_timbrajes,
            'CANT_DOCTOS' => $max_autor,
            'ACEPTAR' => 'Solicitar Numeración',
            'FOLIOS_DISP' => $folios_disp
        ];
    }

    /**
     * @param $ambiente
     * @return void
     * Obtiene el ambiente y setea las variables de clase correspondientes
     * Tanto el envío de boleta electronica como DTEs utilizan el token obtenido desde el servicio de DTEs por algún error en el SII
     * En el caso de consultas de estado de boletas electronicas se utiliza el token obtenido desde el servicio de boletas electronicas
     */
    public function setAmbiente($ambiente, $rut_envia): void
    {
        // Servicio Boleta Electronica
        if(in_array("39", self::$tipos_dte) || in_array("41", self::$tipos_dte)) {
            if ($ambiente == "certificacion" || $ambiente == 1) {
                Sii::setAmbiente(1);
                self::$ambiente = 1;
                self::$url = 'https://pangal.sii.cl/recursos/v1/boleta.electronica.envio'; // url certificación ENVIO BOLETAS
                self::$url_api = 'https://apicert.sii.cl/recursos/v1/boleta.electronica'; // url certificación CONSULTAS BOLETAS

                // IMPORTANTE: token debería ser el obtenido desde la api de boletas electronicas:
                // self::$token = json_decode(file_get_contents(base_path('config.json')))->be->cert->token;
                // pero solo funciona con el token de DTE's certificación/produccion
                // Esto solo sucede con el envío de boletas, no con la consulta de estado de boletas
                self::$token = json_decode(file_get_contents(base_path('config.json')))->$rut_envia->dte->cert->token;
                // Token para consultas de estado de boletas
                self::$token_api = json_decode(file_get_contents(base_path('config.json')))->$rut_envia->be->cert->token;
            } else if ($ambiente == "produccion" || $ambiente == 0) {
                Sii::setAmbiente(0);
                self::$ambiente = 0;
                self::$url = 'https://rahue.sii.cl/recursos/v1/boleta.electronica.envio'; // url producción ENVIO BOLETAS
                self::$url_api = 'https://api.sii.cl/recursos/v1/boleta.electronica'; // url producción CONSULTAS BOLETAS
                // IMPORTANTE: token debería ser el obtenido desde la api de boletas electronicas:
                // self::$token = json_decode(file_get_contents(base_path('config.json')))->be->prod->token;
                // pero solo funciona con el token de DTE's certificación/produccion
                self::$token = json_decode(file_get_contents(base_path('config.json')))->$rut_envia->dte->prod->token;
                // Token para consultas de estado de boletas
                self::$token_api = json_decode(file_get_contents(base_path('config.json')))->$rut_envia->be->prod->token;
            }
            else abort(404);
        } else { // Servicio DTEs
            if ($ambiente == "certificacion"  || $ambiente == 1) {
                Sii::setAmbiente(1);
                self::$ambiente = 1;
                self::$url = 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload'; // url certificación
                self::$token = json_decode(file_get_contents(base_path('config.json')))->$rut_envia->dte->cert->token;
            } else if ($ambiente == "produccion" || $ambiente == 0) {
                Sii::setAmbiente(1);
                self::$ambiente = 0;
                self::$url = 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload'; // url producción
                self::$token = json_decode(file_get_contents(base_path('config.json')))->$rut_envia->dte->prod->token;
            }
            else abort(404);
        }
    }

    public function importarFirma(&$tmp_dir, $cert, $cert_pass)
    {
        // Guardar firma en tmp y que se autoelimine
        try {
            $tmp_dir = TemporaryDirectory::make()->deleteWhenDestroyed();
            $cert_path = $tmp_dir->path("cert.pfx");
            file_put_contents($cert_path, $cert);
        } catch (Exception $e) {
            return [null, [
                'error' => $e->getMessage(),
            ]
            ];
        }

        // Verificar firma
        $Firma = $this->obtenerFirma($cert_path, $cert_pass);
        try {
            $error = Log::read()->msg;
        } catch (Exception $e) {}

        if (isset($error)) {
            return [$cert_path, [
                'error' => [$error, 'Si sus credenciales están bien, verifique que su servidor tenga habilitada la opción Legacy de OpenSSL.'],
            ]
            ];
        }

        return [$cert_path, $Firma];
    }
}

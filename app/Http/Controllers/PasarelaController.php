<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\V1\DteController;
use App\LibreDTE\PDF\Dte;
use App\Mail\DteEnvio;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii\EnvioDte;
use SimpleXMLElement;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\DomCrawler\Crawler;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Support\MessageCollection;

class PasarelaController extends DteController
{
    public function __construct($tipos_dte)
    {
        self::$tipos_dte = $tipos_dte;
    }

    /**
     * @throws GuzzleException
     * @throws \Exception
     */
    protected function generarNuevoCaf($pfx_path, $password, $rut_emp, $dv_emp, $tipo_folio, $cant_doctos)
    {
        $client = new Client(array(
            'cookies' => true,
            'debug' => fopen('php://stderr', 'w'),
        ));

        try {
            $client->request('POST', 'https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi?https://misiir.sii.cl/cgi_misii/siihome.cgi', [
                //'headers' => $header,
                'form_params' => [
                    'referencia' => urlencode('https://misiir.sii.cl/cgi_misii/siihome.cgi'),
                ],
                'curl' => [
                    CURLOPT_SSLCERTTYPE => 'P12',
                    CURLOPT_SSLCERT => $pfx_path,
                    CURLOPT_SSLCERTPASSWD => $password,
                ],
                'allow_redirects' => true,
            ]);
        } catch (GuzzleException $e) {
            Log::write(401, ["Error al autenticarse con el SII.", $e->getMessage(), $e->getTraceAsString()]);
            return false;
        }

        if(self::$ambiente == 0)
            $servidor = 'palena';
        else
            $servidor = 'maullin';

        $solicita_folios = $client->request('POST', "https://$servidor.sii.cl/cvc_cgi/dte/of_solicita_folios_dcto", [
            'form_params' => [
                'RUT_EMP' => $rut_emp,
                'DV_EMP' => $dv_emp,
                'COD_DOCTO' => $tipo_folio,
                'ACEPTAR' => 'Continuar',
            ],
            'verify' => false,
            'allow_redirects' => true,
        ]);

        $html = $solicita_folios->getBody()->getContents();
        if(!$this->verificarRespuestaCaf($html))
            return false;

        // Confirmar folio
        try {
            $data_confirmar_folio = $this->getDataConfirmarFolio($rut_emp, $dv_emp, $tipo_folio, $cant_doctos, $html);
        } catch (\InvalidArgumentException $e) {
            Log::write(["Rut incorrecto o no autorizado.", $e->getMessage(), $e->getTraceAsString()]);
            return false;
        }

        $confirma_folio = $client->request('POST', "https://$servidor.sii.cl/cvc_cgi/dte/of_confirma_folio", [
            'form_params' => $data_confirmar_folio,
        ]);

        $html = $confirma_folio->getBody()->getContents();

        if ($this->esPrimerTimbraje($html)) {
            $data_confimar_folio_inicial = $this->getDataConfimarFolioInicial($html);
            $confirma_folio = $client->request('POST', "https://$servidor.sii.cl/cvc_cgi/dte/of_confirma_folio", [
                'form_params' => $data_confimar_folio_inicial,
            ]);
            $html = $confirma_folio->getBody()->getContents();
        }

        $data_obtener_folio = $this->getDataObtenerFolio($html);
        // Generar folios. Necesario para que el SII genere el archivo xml (caf)
        $response = $client->request('POST', "https://$servidor.sii.cl/cvc_cgi/dte/of_genera_folio", [
            'form_params' => $data_obtener_folio,
        ]);

        $html = $response->getBody()->getContents();
        if(!$this->verificarHtmlCaf($html, $data_obtener_folio))
            return false;

        // Descargar archivo xml (caf)
        $data_generar_archivo = $this->getDataGenerarArchivo($rut_emp, $dv_emp, $data_obtener_folio);
        $response = $client->request('POST', "https://$servidor.sii.cl/cvc_cgi/dte/of_genera_archivo", [
            'form_params' => $data_generar_archivo,
        ]);
        $html = $response->getBody()->getContents();
        return $html;
    }

    private function verificarCookies($html): bool
    {
        // Crear una instancia de DomCrawler
        $crawler = new Crawler($html);

        // Buscar el script que contiene el mensaje
        $script = $crawler->filter('script')->reduce(function (Crawler $node, $i) {
            return strpos($node->text(), 'var msg') !== false;
        });

        if ($script->count() == 0) {
            return true;
        }
        // Obtener el contenido del script y extraer el mensaje
        $message = $script->text();
        $startPos = strpos($message, '\'') + 1;
        $endPos = strpos($message, '\'', $startPos);
        $msg = substr($message, $startPos, $endPos - $startPos);

        // Si el mensaje contiene
        if (stristr($msg, 'Sr. Contribuyente:\n\nPara identificarse utilizando certificado digital seleccione el bot\u00F3n Aceptar,')) {
            // Intentar autenticarse con el SII
            return false;
        } else {
            Log::write(500, $msg);
            return false;
        }
    }

    protected function getDataConfirmarFolio($rut_emp, $dv_emp, $tipo_folio, $cant_doctos, $html): array
    {
        // Obtener el contenido HTML de la respuesta
        $crawler = new Crawler($html);
        $max_autor = $crawler->filter('input[name="MAX_AUTOR"]')->count() > 0 ? $crawler->filter('input[name="MAX_AUTOR"]')->attr('value') : null;
        $afecto_iva = $crawler->filter('input[name="AFECTO_IVA"]')->count() > 0 ? $crawler->filter('input[name="AFECTO_IVA"]')->attr('value') : null;
        $anotacion = $crawler->filter('input[name="ANOTACION"]')->count() > 0 ? $crawler->filter('input[name="ANOTACION"]')->attr('value') : null;
        $con_credito = $crawler->filter('input[name="CON_CREDITO"]')->count() > 0 ? $crawler->filter('input[name="CON_CREDITO"]')->attr('value') : null;
        $con_ajuste = $crawler->filter('input[name="CON_AJUSTE"]')->count() > 0 ? $crawler->filter('input[name="CON_AJUSTE"]')->attr('value') : null;
        $factor = $crawler->filter('input[name="FACTOR"]')->count() > 0 ? $crawler->filter('input[name="FACTOR"]')->attr('value') : null;
        $ult_timbraje = $crawler->filter('input[name="ULT_TIMBRAJE"]')->count() > 0 ? $crawler->filter('input[name="ULT_TIMBRAJE"]')->attr('value') : null;
        $con_historia = $crawler->filter('input[name="CON_HISTORIA"]')->count() > 0 ? $crawler->filter('input[name="CON_HISTORIA"]')->attr('value') : null;
        $folio_ini_cre = $crawler->filter('input[name="FOLIO_INICRE"]')->count() > 0 ? $crawler->filter('input[name="FOLIO_INICRE"]')->attr('value') : null;
        $folio_fin_cre = $crawler->filter('input[name="FOLIO_FINCRE"]')->count() > 0 ? $crawler->filter('input[name="FOLIO_FINCRE"]')->attr('value') : null;
        $fecha_ant = $crawler->filter('input[name="FECHA_ANT"]')->count() > 0 ? $crawler->filter('input[name="FECHA_ANT"]')->attr('value') : null;
        $estado_timbraje = $crawler->filter('input[name="ESTADO_TIMBRAJE"]')->count() > 0 ? $crawler->filter('input[name="ESTADO_TIMBRAJE"]')->attr('value') : null;
        $control = $crawler->filter('input[name="CONTROL"]')->count() > 0 ? $crawler->filter('input[name="CONTROL"]')->attr('value') : null;
        $cant_timbrajes = $crawler->filter('input[name="CANT_TIMBRAJES"]')->count() > 0 ? $crawler->filter('input[name="CANT_TIMBRAJES"]')->attr('value') : null;
        $folio_inicial = $crawler->filter('input[name="FOLIO_INICIAL"]')->count() > 0 ? $crawler->filter('input[name="FOLIO_INICIAL"]')->attr('value') : null;
        $folios_disp = $crawler->filter('input[name="FOLIOS_DISP"]')->count() > 0 ? $crawler->filter('input[name="FOLIOS_DISP"]')->attr('value') : null;

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
            'CANT_DOCTOS' => $cant_doctos,
            'ACEPTAR' => 'Solicitar Numeración',
            'FOLIOS_DISP' => $folios_disp
        ];
    }

    protected function getDataConfimarFolioInicial($html): array
    {
        // Crear el Crawler
        $crawler = new Crawler($html);

        // Extraer los valores de los inputs
        $nomusu = $crawler->filter('input[name="NOMUSU"]')->count() > 0 ? $crawler->filter('input[name="NOMUSU"]')->attr('value') : null;
        $folio_inicial = $crawler->filter('input[name="FOLIO_INICIAL"]')->count() > 0 ? $crawler->filter('input[name="FOLIO_INICIAL"]')->attr('value') : null;
        $con_credito = $crawler->filter('input[name="CON_CREDITO"]')->count() > 0 ? $crawler->filter('input[name="CON_CREDITO"]')->attr('value') : null;
        $con_ajuste = $crawler->filter('input[name="CON_AJUSTE"]')->count() > 0 ? $crawler->filter('input[name="CON_AJUSTE"]')->attr('value') : null;
        $rut_emp = $crawler->filter('input[name="RUT_EMP"]')->count() > 0 ? $crawler->filter('input[name="RUT_EMP"]')->attr('value') : null;
        $dv_emp = $crawler->filter('input[name="DV_EMP"]')->count() > 0 ? $crawler->filter('input[name="DV_EMP"]')->attr('value') : null;
        $cod_docto = $crawler->filter('input[name="COD_DOCTO"]')->count() > 0 ? $crawler->filter('input[name="COD_DOCTO"]')->attr('value') : null;
        $cant_doctos = $crawler->filter('input[name="CANT_DOCTOS"]')->count() > 0 ? $crawler->filter('input[name="CANT_DOCTOS"]')->attr('value') : null;
        $aceptar = $crawler->filter('input[name="ACEPTAR"]')->count() > 0 ? $crawler->filter('input[name="ACEPTAR"]')->attr('value') : null;

        return [
            'NOMUSU' => $nomusu,
            'FOLIO_INICIAL' => $folio_inicial,
            'CON_CREDITO' => $con_credito,
            'CON_AJUSTE' => $con_ajuste,
            'RUT_EMP' => $rut_emp,
            'DV_EMP' => $dv_emp,
            'COD_DOCTO' => $cod_docto,
            'CANT_DOCTOS' => $cant_doctos,
            'ACEPTAR' => $aceptar,
        ];
    }

    protected function getDataObtenerFolio($html): array
    {
        // Obtener el contenido HTML de la respuesta
        $crawler = new Crawler($html);
        // $max_autor = $crawler->filter('input[name="MAX_AUTOR"]')->count() > 0 ? $crawler->filter('input[name="MAX_AUTOR"]')->attr('value') : null;
        $nomusu = $crawler->filter('input[name="NOMUSU"]')->count() > 0 ? $crawler->filter('input[name="NOMUSU"]')->attr('value') : null;
        $con_credito = $crawler->filter('input[name="CON_CREDITO"]')->count() > 0 ? $crawler->filter('input[name="CON_CREDITO"]')->attr('value') : null;
        $con_ajuste = $crawler->filter('input[name="CON_AJUSTE"]')->count() > 0 ? $crawler->filter('input[name="CON_AJUSTE"]')->attr('value') : null;
        $folios_disp = $crawler->filter('input[name="FOLIOS_DISP"]')->count() > 0 ? $crawler->filter('input[name="FOLIOS_DISP"]')->attr('value') : null;
        $max_autor = $crawler->filter('input[name="MAX_AUTOR"]')->count() > 0 ? $crawler->filter('input[name="MAX_AUTOR"]')->attr('value') : null;
        $ult_timbraje = $crawler->filter('input[name="ULT_TIMBRAJE"]')->count() > 0 ? $crawler->filter('input[name="ULT_TIMBRAJE"]')->attr('value') : null;
        $con_historia = $crawler->filter('input[name="CON_HISTORIA"]')->count() > 0 ? $crawler->filter('input[name="CON_HISTORIA"]')->attr('value') : null;
        $cant_timbrajes = $crawler->filter('input[name="CANT_TIMBRAJES"]')->count() > 0 ? $crawler->filter('input[name="CANT_TIMBRAJES"]')->attr('value') : null;
        $folio_ini_cre = $crawler->filter('input[name="FOLIO_INICRE"]')->count() > 0 ? $crawler->filter('input[name="FOLIO_INICRE"]')->attr('value') : null;
        $folio_fin_cre = $crawler->filter('input[name="FOLIO_FINCRE"]')->count() > 0 ? $crawler->filter('input[name="FOLIO_FINCRE"]')->attr('value') : null;
        $fecha_ant = $crawler->filter('input[name="FECHA_ANT"]')->count() > 0 ? $crawler->filter('input[name="FECHA_ANT"]')->attr('value') : null;
        $estado_timbraje = $crawler->filter('input[name="ESTADO_TIMBRAJE"]')->count() > 0 ? $crawler->filter('input[name="ESTADO_TIMBRAJE"]')->attr('value') : null;
        $control = $crawler->filter('input[name="CONTROL"]')->count() > 0 ? $crawler->filter('input[name="CONTROL"]')->attr('value') : null;
        $folio_ini = $crawler->filter('input[name="FOLIO_INI"]')->count() > 0 ? $crawler->filter('input[name="FOLIO_INI"]')->attr('value') : null;
        $folio_fin = $crawler->filter('input[name="FOLIO_FIN"]')->count() > 0 ? $crawler->filter('input[name="FOLIO_FIN"]')->attr('value') : null;
        $dia = $crawler->filter('input[name="DIA"]')->count() > 0 ? $crawler->filter('input[name="DIA"]')->attr('value') : null;
        $mes = $crawler->filter('input[name="MES"]')->count() > 0 ? $crawler->filter('input[name="MES"]')->attr('value') : null;
        $ano = $crawler->filter('input[name="ANO"]')->count() > 0 ? $crawler->filter('input[name="ANO"]')->attr('value') : null;
        $hora = $crawler->filter('input[name="HORA"]')->count() > 0 ? $crawler->filter('input[name="HORA"]')->attr('value') : null;
        $minuto = $crawler->filter('input[name="MINUTO"]')->count() > 0 ? $crawler->filter('input[name="MINUTO"]')->attr('value') : null;
        $rut_emp = $crawler->filter('input[name="RUT_EMP"]')->count() > 0 ? $crawler->filter('input[name="RUT_EMP"]')->attr('value') : null;
        $dv_emp = $crawler->filter('input[name="DV_EMP"]')->count() > 0 ? $crawler->filter('input[name="DV_EMP"]')->attr('value') : null;
        $cod_docto = $crawler->filter('input[name="COD_DOCTO"]')->count() > 0 ? $crawler->filter('input[name="COD_DOCTO"]')->attr('value') : null;
        $cant_doctos = $crawler->filter('input[name="CANT_DOCTOS"]')->count() > 0 ? $crawler->filter('input[name="CANT_DOCTOS"]')->attr('value') : null;
        $aceptar = $crawler->filter('input[name="ACEPTAR"]')->count() > 0 ? $crawler->filter('input[name="ACEPTAR"]')->attr('value') : null;

        return [
            'NOMUSU' => $nomusu,
            'CON_CREDITO' => $con_credito,
            'CON_AJUSTE' => $con_ajuste,
            'FOLIOS_DISP' => $folios_disp,
            'MAX_AUTOR' => $max_autor,
            'ULT_TIMBRAJE' => $ult_timbraje,
            'CON_HISTORIA' => $con_historia,
            'CANT_TIMBRAJES' => $cant_timbrajes,
            'CON_AJUSTE' => $con_ajuste,
            'FOLIO_INICRE' => $folio_ini_cre,
            'FOLIO_FINCRE' => $folio_fin_cre,
            'FECHA_ANT' => $fecha_ant,
            'ESTADO_TIMBRAJE' => $estado_timbraje,
            'CONTROL' => $control,
            'FOLIO_INI' => intval($folio_ini) == 0 ? "1" : $folio_ini, // Si es el primer folio (0) retornar 1
            'FOLIO_FIN' => intval($folio_ini) == 0 ? $cant_doctos : $folio_fin, // Si es el primer folio retornar folio final ( = $cant_doctos)
            'DIA' => $dia,
            'MES' => $mes,
            'ANO' => $ano,
            'HORA' => $hora,
            'MINUTO' => $minuto,
            'RUT_EMP' => $rut_emp,
            'DV_EMP' => $dv_emp,
            'COD_DOCTO' => $cod_docto,
            'CANT_DOCTOS' => $cant_doctos,
            'ACEPTAR' => $aceptar
        ];
    }

    protected function getDataGenerarArchivo($rut_emp, $dv_emp, $data): array
    {
        return [
            'RUT_EMP' => $rut_emp,
            'DV_EMP' => $dv_emp,
            'COD_DOCTO' => $data['COD_DOCTO'],
            'FOLIO_INI' => $data['FOLIO_INI'],
            'FOLIO_FIN' => $data['FOLIO_FIN'],
            'FECHA' => $data['ANO'] . '-' . $data['MES'] . '-' . $data['DIA'],
            'ACEPTAR' => 'AQUI'
        ];
    }

    /**
     * Encapsula las respuestas de error del SII
     */
    private function verificarRespuestaCaf($html): bool
    {
        // Obtener el contenido HTML de la respuesta
        $crawler = new Crawler($html);

        $texto = $crawler->filter('font[class="texto"]')->last()->text();
        if (isset($texto)) {
            if (stristr($texto, "Mediante la presente solicitud declaro conocer y aceptar las condiciones que el SII establece para la autorización de Timbraje de Documentos Electrónicos.")) {
                return true;
            }
            if (stristr($texto, "NO SE AUTORIZA TIMBRAJE ELECTRÓNICO")){
                Log::write($texto);
                return false;
            }
            if (stristr($texto, "No ha sido posible completar su solicitud.")){
                Log::write($texto);
                return false;
            }
        }
        Log::write($html);
        return false;
    }

    public function esPrimerTimbraje($html)
    {
        $crawler = new Crawler($html);
        $found = false;
        $crawler->filter('font.texto')->each(function (Crawler $node) use (&$text_arr, &$found) {
            $text_arr[] = $node->text();
            if (stripos($node->text(), "No registra timbraje anterior.") !== false) {
                $found = true;
            }
        });
        if ($found)
            return true;
    }

    public function verificarHtmlCaf($html, $data): bool
    {
        $crawler = new Crawler($html);

        // Verificar si el SII autorizó el CAF
        $found = false;
        $crawler->filter('font.texto')->each(function (Crawler $node) use (&$text_arr, &$found) {
            $text_arr[] = $node->text();
            if (stristr($node->text(), "Servicio de Impuestos Internos ha autorizado")) {
                $found = true;
            }
        });
        if ($found)
            return true;

        // Si texto existe
        if (isset($text_arr)) {
            foreach ($text_arr as $texto) {
                if (stristr($texto, "No ha sido posible completar su solicitud.")) {
                    if (stristr($texto, "La cantidad de documentos a timbrar debe ser menor o igual al máximo autorizado")) {
                        Log::write("$texto MAX_AUTOR={$data['MAX_AUTOR']}");
                        return false;
                    }

                    if (stristr($texto, "diferencia entre el rango de folios solicitado con el rango solicitado la última vez")) {
                        Log::write($texto);
                        return false;
                    }

                    if (stristr($texto, "ADVERTENCIA")) {
                        Log::write($texto);
                        return false;
                    }

                    Log::write($texto);
                    return false;
                }
                if (stristr($texto, "usted tiene disponible una cantidad de folios suficiente para emitir documentos")) {
                    Log::write($texto);
                    return false;
                }
            }
        }

        // En caso de dar error distinto a los ya encapsulados, retornar el html en base64
        Log::write("No ha sido posible realizar su solicitud. Comuníquese con su soporte.");
        Log::write(base64_encode($html));
        return false;
    }

    private function obtenerCookies()
    {
        $pfx_path = env('CERT_PATH');
        $password = env('CERT_PASS');
        $url = 'https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi?https://misiir.sii.cl/cgi_misii/siihome.cgi';

        $client = new Client(array(
            'cookies' => true,
            'debug' => fopen('php://stderr', 'w'),
        ));
        try {
            $response = $client->request('POST', $url, [
                //'headers' => $header,
                'form_params' => [
                    'referencia' => urlencode('https://misiir.sii.cl/cgi_misii/siihome.cgi'),
                ],
                'curl' => [
                    CURLOPT_SSLCERTTYPE => 'P12',
                    CURLOPT_SSLCERT => $pfx_path,
                    CURLOPT_SSLCERTPASSWD => $password,
                ],
                'allow_redirects' => true,
            ]);
        } catch (GuzzleException $e) {
            Log::write(401, "Error al autenticarse con el SII.\n{$e->getMessage()}");
            return false;
        }

        //$cookies = $response->getHeader('Set-Cookie');
        //$cookiesString = implode('; ', $cookies);
        // Eliminar el atributo 'secure' de cada cookie
        //$cookiesString = preg_replace('/\s*secure/i', '', $cookiesString);

        //file_put_contents(base_path('cookies.json'), json_encode(['cookies'=> $cookiesString]));
        return $client;
    }

    /**
     * Procesar los adjuntos del correo.
     * Función utilizada por importarDtesCorreo()
     */
    protected function procesarAttachments($message): array
    {
        $dte_arr = [];
        $pdf_arr = [];
        /* @var  MessageCollection $Attachments*/
        $Attachments = $message->getAttachments();
        if (!$Attachments->isEmpty()) {
            /**
             * Obtener el contenido del adjunto
             *
             * @var Attachment $Attachment
             * @var string $content
             */
            foreach ($Attachments as $Attachment) {
                // Verificar si el adjunto es un xml
                if(str_ends_with(strtolower($Attachment->getName()), '.xml')) {
                    // Ver si el xmles un dte o una respuesta a un dte
                    $Xml = new SimpleXMLElement($Attachment->getContent());
                    $tipoXml = $Xml[0]->getName();
                    if($tipoXml == 'EnvioDTE') {
                        $dte_arr[] = $Attachment;
                    }
                } elseif (str_ends_with(strtolower($Attachment->getName()), '.pdf')) {
                    $pdf_arr[] = $Attachment;
                }
            }
        }
        return [$dte_arr, $pdf_arr];
    }

    /**
     * Quitar firmas de los DTEs
     * Función utilizada por importarDtesCorreo()
     */
    protected function quitarFirmas($attachments): array
    {
        $attachments_arr = [];
        /**
         * Obtener el contenido del adjunto
         *
         * @var Attachment $Attachment
         * @var string $content
         */
        foreach ($attachments as $Attachment) {
            $Xml = new SimpleXMLElement($Attachment->getContent());

            // Eliminar las firmas del XML si existen
            if (isset($Xml->Signature)) {
                unset($Xml->Signature);
            }

            if (isset($Xml->SetDTE->Signature)) {
                unset($Xml->SetDTE->Signature);
            }

            foreach ($Xml->SetDTE->DTE as $Dte) {
                if (isset($Dte->Signature)) {
                    unset($Dte->Signature);
                }
                foreach ($Dte->Documento as $Documento) {
                    if (isset($Documento->TED)) {
                        unset($Documento->TED);
                    }
                }
            }

            $attachments_arr[] = [
                "filename" => $Attachment->getName(),
                "content" =>  json_decode(json_encode($Xml), true),
            ];
        }
        return $attachments_arr;
    }

    /**
     * Generar DTE de respuesta sobre la aceptación o rechazo de un dte
     */
    protected function generarRespuestaDocumento($estado, $glosa, $dte_xml, $rut_receptor_esperado, $Firma)
    {
        $this->timestamp = Carbon::now('America/Santiago');

        // Cargar EnvioDTE y extraer arreglo con datos de carátula y DTEs
        $EnvioDte = new \sasco\LibreDTE\Sii\EnvioDte();
        $EnvioDte->loadXML($dte_xml);
        $caratula = $EnvioDte->getCaratula();
        $Documentos = $EnvioDte->getDocumentos();

        $id_respuesta = 1; // se debe administrar
        $cod_envio = 1; // Secuencia de envío, se debe administrar

        // caratula
        $rut_emisor_esperado = $EnvioDte->getEmisor();
        $caratula_respuesta = [
            'RutResponde' => $rut_receptor_esperado,
            'RutRecibe' => $rut_emisor_esperado,
            'IdRespuesta' => $id_respuesta,
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
                'CodEnvio' => $cod_envio, // Secuencia de envío
                'EstadoDTE' => $estado,
                'EstadoDTEGlosa' => \sasco\LibreDTE\Sii\RespuestaEnvio::$estados['respuesta_documento'][$estado].$glosa,
            ]);
        }

        // asignar carátula y Firma
        $RespuestaEnvio->setCaratula($caratula_respuesta);
        $RespuestaEnvio->setFirma($Firma);

        // generar XML
        $xml = $RespuestaEnvio->generar();

        // validar schema del XML que se generó
        if ($RespuestaEnvio->schemaValidate()) {
            // Guardar respuesta en la base de datos
            return $xml;
        }
        return false;
    }

    public function generarResumenVentasDiarias(array $caratula, array $resumen, FirmaElectronica $Firma)
    {
        $caratula_merge = array_merge([
            '@attributes' => [
                'version' => '1.0',
            ],
            'RutEmisor' => false,
            'RutEnvia' => $Firma->getID(),
            'FchResol' => false,
            'NroResol' => false,
            'FchInicio' => false,
            'FchFinal' => false,
            'Correlativo' => false,
            'SecEnvio' => 1,
            'TmstFirmaEnv' => date('Y-m-d\TH:i:s'),
        ], $caratula);
        $id = 'CONSUMO_FOLIO_'.str_replace('-', '', $caratula['RutEmisor']).'_'.str_replace('-', '', $caratula['FchInicio']).'_'.date('U');

        $consumo_folios = (new \sasco\LibreDTE\XML())->generate([
            'ConsumoFolios' => [
                '@attributes' => [
                    'xmlns' => 'http://www.sii.cl/SiiDte',
                    'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
                    'xsi:schemaLocation' => 'http://www.sii.cl/SiiDte ConsumoFolio_v10.xsd',
                    'version' => '1.0',
                ],
                'DocumentoConsumoFolios' => [
                    '@attributes' => [
                        'ID' => $id,
                    ],
                    'Caratula' => $caratula_merge,
                    'Resumen' => $resumen,
                ],
            ]
        ])->saveXML();
        // firmar XML del envío y entregar
        return $Firma->signXML($consumo_folios, '#'.$id, 'DocumentoConsumoFolios', true);
    }

    public function xmlPdf($xml, $continuo = false, $logob64 = false, $observaciones = false, $cedible = false, $copia_cedible = false, $footer = false, $tickets = false): array|false
    {
        if ($logob64){
            try {
                $tmp_dir = TemporaryDirectory::make()->deleteWhenDestroyed();
                $logo_path = $tmp_dir->path("logo.png");
                file_put_contents($logo_path, base64_decode($logob64));
                file_get_contents($logo_path);
            } catch (\Exception $e) {
                $logob64 = false;
            }
        }

        // Cargar EnvioDTE y extraer arreglo con datos de carátula y DTEs
        $EnvioDte = new EnvioDte();
        //$EnvioDte->loadXML(base64_decode($request->xmlb64));
        $EnvioDte->loadXML($xml);
        $Caratula = $EnvioDte->getCaratula();
        $Documentos = $EnvioDte->getDocumentos();

        // procesar cada DTEs e ir agregándolo al PDF
        $pdfb64_arr = [];
        foreach ($Documentos as $DTE) {
            $filename = 'dte_' . $Caratula['RutEmisor'] . '_' . $DTE->getID();
            if (!$DTE->getDatos()) {
                Log::write("No se pudieron obtener los datos del DTE");
                return false;
            }
            $dte = $DTE->getDatos();
            // Utilizar librería editada \App\LibreDTE\PDF\Dte
            $pdf = new Dte($continuo); // =false hoja carta, =true papel contínuo (false por defecto si no se pasa)
            // Utilizar librería original
            //$pdf = new \sasco\LibreDTE\Sii\Dte\PDF\Dte($continuo);
            // Set casa matriz
            $pdf->setCasaMatriz('CASA MATRIZ');
            if ($footer)
                $pdf->setFooterText();
            if ($logob64)
                $pdf->setLogo($logo_path); // debe ser PNG!
            $pdf->setResolucion(['FchResol' => $Caratula['FchResol'], 'NroResol' => $Caratula['NroResol']]);
            $pdf->setCedible($cedible);
            // Si existen observaciones
            if($observaciones)
                $dte['Observaciones'] = $observaciones;
            $pdf->agregar($dte, $DTE->getTED());
            $nombre = "{$Caratula['RutEmisor']}.{$dte['Encabezado']['IdDoc']['TipoDTE']}.{$dte['Encabezado']['IdDoc']['Folio']}";
            if($copia_cedible) {
                for ($i = 0; $i < $copia_cedible; $i++){
                    if ($logob64)
                        $pdf->setLogo($logo_path); // debe ser PNG!
                    $pdf->setResolucion(['FchResol' => $Caratula['FchResol'], 'NroResol' => $Caratula['NroResol']]);
                    $pdf->setCedible(true); // siempre true
                    // Si existen observaciones
                    if($observaciones)
                        $dte['Observaciones'] = $observaciones;
                    $pdf->agregar($dte, $DTE->getTED());
                }
            }
            if($tickets && $continuo) {
                $continuo = $continuo === true ? 80 : $continuo;
                if(in_array($continuo, array(0, 70, 75, 77, 80, 110)))
                    $pdf->agregarTickets($tickets, ($continuo-58)/2, 190, $continuo);
                else if($continuo == 57)
                    $pdf->agregarTickets($tickets, 2, 190, $continuo);
            }
            //file_put_contents(base_path()."/pdf.pdf", $pdf->getPDFData());
            $pdfb64_arr[$nombre] = chunk_split(base64_encode($pdf->getPDFData()));
        }

        return $pdfb64_arr;
    }

    public function enviarDteReceptor($envio_dte_xml, $message, $envio_arr, $pdfb64_arr = false, $formato_impresion = false, $observaciones = false, $logob64 = false, $cedible = false, $copia_cedible = false, $footer = false, $tickets = false): bool|array
    {
        // Preparar datos
        $attatchments = [
            [
                'filename' => $this->parseFileName($envio_dte_xml),
                'data' => $envio_dte_xml
            ],
        ];

        // Agregar pdfs según xml enviado al SII
        if ($pdfb64_arr) {
            foreach ($pdfb64_arr as $key => $pdf) {
                $attatchments[] = [
                    'filename' => str_replace('.', '_', $key) . '.pdf',
                    'data' => base64_decode($pdf),
                    'mime' => 'application/pdf'
                ];
            }
        } else {
            // Asigna true si es 'H' False caso contrario
            $continuo = $formato_impresion == 'T';
            if(in_array($formato_impresion, array(0, 57, 70, 75, 77, 80, 110)))
                $continuo = $formato_impresion;

            // Llama a la función xmlPdf con los argumentos claros
            $pdfb64_arr = $this->xmlPdf($envio_dte_xml, $continuo, $logob64, $observaciones, $cedible, $copia_cedible, $footer, $tickets);

            foreach ($pdfb64_arr as $key => $pdf) {
                $attatchments[] = [
                    'filename' => str_replace('.', '_', $key).".pdf",
                    'data' => base64_decode($pdf),
                    'mime' => 'application/pdf'
                ];
            }
        }

        // Enviar respuesta por correo
        try {
            // Configurar la conexión SMTP
            Config::set('mail.mailers.smtp.host', $envio_arr['host']);
            Config::set('mail.mailers.smtp.port', $envio_arr['port']);
            Config::set('mail.mailers.smtp.username', $envio_arr['mail']);
            Config::set('mail.mailers.smtp.password', base64_decode($envio_arr['password']));
            Config::set('mail.from.address', $envio_arr['mail']);
            Config::set('mail.from.name', $message['emisor']);
            Mail::to($envio_arr['correo_receptor'])->send(new DteEnvio($message, $attatchments));

        } catch (\Exception $e) {
            Log::write("Error al enviar correo con DTEs al receptor");
            Log::write($e->getMessage());
            return false;
        }
        $arr = [
            'xmlb64' =>base64_encode($envio_dte_xml),
            'pdfb64' =>$pdfb64_arr
        ];

        return $arr;
    }

    protected function obtenerResumenCompraVenta($pfx_path, $password, $rut_emp, $dv_emp, $tipo_folio, $estado, $operacion, $periodo)
    {
        $jar = new \GuzzleHttp\Cookie\CookieJar;
        $client = new Client(array(
            'cookies' => $jar,
            'debug' => fopen('php://stderr', 'w'),
        ));

        try {
            $r = $client->request('POST', 'https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi?https://misiir.sii.cl/cgi_misii/siihome.cgi', [
                //'headers' => $header,
                'form_params' => [
                    'referencia' => urlencode('https://misiir.sii.cl/cgi_misii/siihome.cgi'),
                ],
                'curl' => [
                    CURLOPT_SSLCERTTYPE => 'P12',
                    CURLOPT_SSLCERT => $pfx_path,
                    CURLOPT_SSLCERTPASSWD => $password,
                ],
                'allow_redirects' => true,
            ]);
        } catch (GuzzleException $e) {
            Log::write(401, ["Error al autenticarse con el SII.", $e->getMessage(), $e->getTraceAsString()]);
            return false;
        }

        $cookie = $jar->getCookieByName('CSESSIONID');
        $get_resumen = $client->request('POST', "https://www4.sii.cl/consdcvinternetui/services/data/facadeService/getResumenExport", [
            'json' => [
                'data' => [
                    'busquedaInicial' => true,
                    'dvEmisor' => $dv_emp,
                    'estadoContab' => $estado,
                    'operacion' => $operacion,
                    'ptributario' => $periodo,
                    'rutEmisor' => $rut_emp,
                    ],
                'metaData' => [
                    'conversationId' => $cookie->getValue(),
                    'namespace' => 'cl.sii.sdi.lob.diii.consdcv.data.api.interfaces.FacadeService/getResumenExport',
                    'page' => null,
                    'transactionId' => $this->generate_uuid_v4()
                ]
            ],
            'verify' => false,
            'allow_redirects' => true,
        ]);

        try {
            $csv_data = $get_resumen->getBody()->getContents();
            $csv_data = json_decode($csv_data);
            if (!$csv_data->data || $csv_data == null)
                return false;
        } catch (\Exception $e) {
            return false;
        }
        return $csv_data->data;
    }

    protected function obtenerDetalleCompraVenta($pfx_path, $password, $rut_emp, $dv_emp, $tipo_folio, $estado, $operacion, $periodo)
    {
        $jar = new \GuzzleHttp\Cookie\CookieJar;
        $client = new Client(array(
            'cookies' => $jar,
            'debug' => fopen('php://stderr', 'w'),
        ));

        try {
            $r = $client->request('POST', 'https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi?https://misiir.sii.cl/cgi_misii/siihome.cgi', [
                //'headers' => $header,
                'form_params' => [
                    'referencia' => urlencode('https://misiir.sii.cl/cgi_misii/siihome.cgi'),
                ],
                'curl' => [
                    CURLOPT_SSLCERTTYPE => 'P12',
                    CURLOPT_SSLCERT => $pfx_path,
                    CURLOPT_SSLCERTPASSWD => $password,
                ],
                'allow_redirects' => true,
            ]);
        } catch (GuzzleException $e) {
            Log::write(401, ["Error al autenticarse con el SII.", $e->getMessage(), $e->getTraceAsString()]);
            return false;
        }

        $cookie = $jar->getCookieByName('CSESSIONID');
        $tipo_folio ?: $tipo_folio = 0;
        if (strtoupper($operacion) == 'VENTA')
            $estado = '';
        $get_detalle_export = $client->request('POST', "https://www4.sii.cl/consdcvinternetui/services/data/facadeService/getDetalle".ucfirst(strtolower($operacion))."Export", [
            'json' => [
                'data' => [
                    'codTipoDoc' => $tipo_folio,
                    'dvEmisor' => $dv_emp,
                    'estadoContab' => $estado,
                    'operacion' => $operacion,
                    'ptributario' => $periodo,
                    'rutEmisor' => $rut_emp,
                ],
                'metaData' => [
                    'conversationId' => $cookie->getValue(),
                    'namespace' => "cl.sii.sdi.lob.diii.consdcv.data.api.interfaces.FacadeService/getDetalle".ucfirst(strtolower($operacion))."Export",
                    'page' => null,
                    'transactionId' => $this->generate_uuid_v4()
                ]
            ],
            'verify' => false,
            'allow_redirects' => true,
        ]);

        try {
            $csv_data = $get_detalle_export->getBody()->getContents();
            $csv_data = json_decode($csv_data);
            if (!$csv_data->data || $csv_data == null)
                return false;
        } catch (\Exception $e) {
            return false;
        }
        return $csv_data->data;
    }

    function generate_uuid_v4() {
        $data = openssl_random_pseudo_bytes(16);
        // Establece los bits de la versión a 0100 (versión 4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Establece los bits más significativos a 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Devuelve el UUID en el formato estándar
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

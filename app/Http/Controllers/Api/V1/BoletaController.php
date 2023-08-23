<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
        //$schema->in(json_decode($rbody)); // Exception: Required property missing: id at #->properties:orders->items[1]->#/definitions/order

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

        // Primer folio a usar para envio de set de pruebas
        $folios = [
            39 => 1, // boleta electrónica
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
            // Obtención de detalles como array.
            $detalles = [];
            //return gettype($detalles);
            //return $detalles;
            // Modelo boleta
            $modeloBoleta = [
                "Encabezado" => [
                    "IdDoc" => [
                        "TipoDTE" => 39,
                        "Folio" => $folios[39],
                    ],
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
                'Referencia' => [
                    [
                        'TpoDocRef' => 'SET',
                        'FolioRef' => $folios[39],
                        'RazonRef' => 'CASO-'.$folios[39],
                    ],
                ]
            ];

            // Agregar Detalle
            foreach ($boleta->Detalle as $detalle) {
                $modeloBoleta["Detalle"][] = json_decode(json_encode($detalle), true);
            }

            // Agregar modelo boleta
            $boletas[] = $modeloBoleta;

            // Aumentar cantidad folios
            $folios[39]++;
        }
        // Se descuenta 1, ya que, se aumenta al final del foreach y no concuerda con la cantidad de folios.
        $folios[39]--;

        // Objetos de Firma y Folios
        $Firma = new FirmaElectronica($config['firma']);
        $Folios = [];
        foreach ($folios as $tipo => $cantidad)
        $Folios[$tipo] = new Folios(file_get_contents(base_path().'\xml\folios\\'.$tipo.'.xml'));

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

        // Solicitar token
        $token = Autenticacion::getToken($config['firma']);

        // si hubo errores se muestran
        if (!$token) {
            foreach (Log::readAll() as $error)
                echo $error,"\n";
            exit;
        }

        // solicitar ambiente desarrollo con parámetro
        //echo Sii::setServidor('maullin',Sii::CERTIFICACION);
        //echo Sii::wsdl('CrSeed', Sii::CERTIFICACION);

        // Enviar DTE
        $RutEnvia = $Firma->getID(); // RUT autorizado para enviar DTEs
        $RutEmisor = $boletas[0]['Encabezado']['Emisor']['RUTEmisor']; // RUT del emisor del DTE
        $result = Sii::enviar($RutEnvia, $RutEmisor, $EnvioDTExml, $token);

        // Si hubo algún error al enviar al servidor mostrar
        if ($result===false) {
            foreach (Log::readAll() as $error)
                //echo $error,"\n";
            exit;
        }

        // Mostrar resultado del envío
        echo $result;

        if ($result->STATUS!='0') {
            foreach (Log::readAll() as $error)
                echo $error,"\n";
            exit;
        }
        echo 'DTE envíado. Track ID '.$result->TRACKID,"\n";
    }

    public function status()
    {
        // Firma .p12
        $config = [
            'firma' => [
                'file' => env("CERT_PATH", ""),
                //'data' => '', // contenido del archivo certificado.p12
                'pass' => env("CERT_PASS", "")
            ],
        ];
        // solicitar token
        $token = Autenticacion::getToken($config['firma']);

        if (!$token) {
            foreach (Log::readAll() as $error)
                echo $error,"\n";
            exit;
        }

        // consultar estado dte
        $xml = Sii::request('QueryEstDte', 'getEstDte', [
            'RutConsultante'    => '',
            'DvConsultante'     => '',
            'RutCompania'       => '',
            'DvCompania'        => '',
            'RutReceptor'       => '',
            'DvReceptor'        => '',
            'TipoDte'           => '',
            'FolioDte'          => '',
            'FechaEmisionDte'   => '',
            'MontoDte'          => '',
            'token'             => $token,
        ]);

        // si el estado se pudo recuperar se muestra
        if ($xml!==false) {
            print_r((array)$xml->xpath('/SII:RESPUESTA/SII:RESP_HDR')[0]);
        }

        // si hubo errores se muestran
        foreach (Log::readAll() as $error)
            echo $error,"\n";
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\Sii\Folios;
use sasco\LibreDTE\XML;
use Swaggest\JsonSchema\Schema;

class BoletaController extends Controller
{
    public function index(Request $request) {
        // Leer string como json
        $rbody = json_encode($request->json()->all());

        // Transformar a json
        $json = json_decode($rbody, true);

        // Schema del json
        $schemaJson = file_get_contents(base_path().'\SchemasSwagger\Boleta.json');

        // Validar json
        $schema = Schema::import(json_decode($schemaJson));
        $schema->in(json_decode($rbody)); // Exception: Required property missing: id at #->properties:orders->items[1]->#/definitions/order

        //$jsonArr = var_dump($json);
        return json_encode($json['EnvioBOLETA']['SetDTE']['Caratula']);

    }

    public function setPruebas() {
        // primer folio a usar para envio de set de pruebas
        $folios = [
            39 => 1, // boleta electrónica
            61 => 56, // nota de crédito electrónicas
        ];

// caratula para el envío de los dte
        $caratula = [
            //'RutEnvia' => '11222333-4', // se obtiene automáticamente de la firma
            'RutReceptor' => '60803000-K',
            'FchResol' => '2014-12-05',
            'NroResol' => 0,
        ];

// datos del emisor
        $Emisor = [
            'RUTEmisor' => '76192083-9',
            'RznSoc' => 'SASCO SpA', // tag verdadero es RznSocEmisor, pero se permite usar el de DTE
            'GiroEmis' => 'Servicios integrales de informática', // tag verdadero es GiroEmisor, pero se permite usar el de DTE
            'Acteco' => 726000, // en boleta este tag no va y se quita al normalizar (se deja para nota de crédito)
            'DirOrigen' => 'Santiago',
            'CmnaOrigen' => 'Santiago',
        ];

// datos el recepor
        $Receptor = [
            'RUTRecep' => '55666777-8',
            'RznSocRecep' => 'Cliente S.A.',
            'DirRecep' => 'Santiago',
            'CmnaRecep' => 'Santiago',
        ];


        // Firma .p12
        $config = [
            'firma' => [
                'file' => base_path().'\CertificadoPersonalCIR.pfx',
                //'data' => '', // contenido del archivo certificado.p12
                'pass' => env("CERT_PASS", "")
            ],
        ];

        $set_pruebas = [
            // CASO 1
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 39,
                        'Folio' => $folios[39],
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => $Receptor,
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'koyak el chupete',
                        'QtyItem' => 12,
                        'PrcItem' => 170,
                    ],
                    [
                        'NmbItem' => 'cuaderno pre U',
                        'QtyItem' => 20,
                        'PrcItem' => 1050,
                    ],
                ],
            ],
            // CASO 2
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 39,
                        'Folio' => $folios[39]+1,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => $Receptor,
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'pizza española el italiano',
                        'QtyItem' => 29,
                        'PrcItem' => 2990,
                    ],
                ],
            ],
            // CASO 3
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 39,
                        'Folio' => $folios[39]+2,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => $Receptor,
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'sorpresa de cumpleaño',
                        'QtyItem' => 90,
                        'PrcItem' => 300,
                    ],
                    [
                        'NmbItem' => 'gorros superhéroes',
                        'QtyItem' => 13,
                        'PrcItem' => 840,
                    ],
                ],
            ],
            // CASO 4
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 39,
                        'Folio' => $folios[39]+3,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => $Receptor,
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'item afecto 1',
                        'QtyItem' => 12,
                        'PrcItem' => 1500,
                    ],
                    [
                        'IndExe' => 1,
                        'NmbItem' => 'item exento 2',
                        'QtyItem' => 2,
                        'PrcItem' => 2590,
                    ],
                    [
                        'IndExe' => 1,
                        'NmbItem' => 'item exento 3',
                        'QtyItem' => 1,
                        'PrcItem' => 5000,
                    ],
                ],
            ],
            // CASO 5
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 39,
                        'Folio' => $folios[39]+4,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => $Receptor,
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'combo Italiano + bebida',
                        'QtyItem' => 12,
                        'PrcItem' => 1690,
                    ],
                ],
            ],
            // CASO 6
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 39,
                        'Folio' => $folios[39]+5,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => $Receptor,
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'item afecto 1',
                        'QtyItem' => 5,
                        'PrcItem' => 25,
                    ],
                    [
                        'IndExe' => 1,
                        'NmbItem' => 'item exento 2',
                        'QtyItem' => 1,
                        'PrcItem' => 20000,
                    ],
                ],
            ],
            // CASO 7
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 39,
                        'Folio' => $folios[39]+6,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => $Receptor,
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'goma de borrar school',
                        'QtyItem' => 5,
                        'PrcItem' => 340,
                    ],
                ],
            ],
            // CASO 8
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 39,
                        'Folio' => $folios[39]+7,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => $Receptor,
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'Té ceylan',
                        'QtyItem' => 5,
                        'PrcItem' => 3178,
                    ],
                    [
                        'NmbItem' => 'Jugo super natural de 3/4 lts',
                        'QtyItem' => 38,
                        'PrcItem' => 150,
                    ],
                ],
            ],
            // CASO 9
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 39,
                        'Folio' => $folios[39]+8,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => $Receptor,
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'lápiz tinta azul',
                        'QtyItem' => 10,
                        'PrcItem' => 290,
                    ],
                    [
                        'NmbItem' => 'lápiz tinta rojo',
                        'QtyItem' => 5,
                        'PrcItem' => 250,
                    ],
                    [
                        'NmbItem' => 'lápiz tinta mágica',
                        'QtyItem' => 3,
                        'PrcItem' => 790,
                    ],
                    [
                        'NmbItem' => 'lápiz corrector',
                        'QtyItem' => 2,
                        'PrcItem' => 1190,
                    ],
                    [
                        'NmbItem' => 'corchetera',
                        'QtyItem' => 1,
                        'PrcItem' => 3500,
                    ],
                ],
            ],
            // CASO 10
            [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => 39,
                        'Folio' => $folios[39]+9,
                    ],
                    'Emisor' => $Emisor,
                    'Receptor' => $Receptor,
                ],
                'Detalle' => [
                    [
                        'NmbItem' => 'Clavo Galvanizado 3/4"',
                        'QtyItem' => 3.8,
                        'UnmdItem' => 'Kg',
                        'PrcItem' => 710,
                    ],
                ],
            ],
        ];

        // Objetos de Firma y Folios
        $Firma = new FirmaElectronica($config['firma']);
        $Folios = [];
        foreach ($folios as $tipo => $cantidad)
            $Folios[$tipo] = new Folios(file_get_contents(base_path().'\xml\folios\\'.$tipo.'.xml'));

        // generar cada DTE, timbrar, firmar y agregar al sobre de EnvioBOLETA
        $EnvioDTE = new EnvioDte();
        foreach ($set_pruebas as $documento) {
            $DTE = new Dte($documento);
            if (!$DTE->timbrar($Folios[$DTE->getTipo()]))
                break;
            if (!$DTE->firmar($Firma))
                break;
            $EnvioDTE->agregar($DTE);
        }
        $EnvioDTE->setFirma($Firma);
        $EnvioDTE->setCaratula($caratula);
        $EnvioDTExml = $EnvioDTE->generar();
        if ($EnvioDTE->schemaValidate()) {
            // is writable entrega false siempre, buscar por qué
          if (is_writable('xml/EnvioBOLETA.xml'))
                file_put_contents('xml/EnvioBOLETA.xml', $EnvioDTExml); // guardar XML en sistema de archivos
            echo $EnvioDTExml;
        }
    }
}

<?php

use function Pest\Stressless\stress;

beforeEach(function () use (&$url, &$token, &$caratula, &$emisor, &$body) {
    $url = 'https://api.logiciel.cl/api/v1/pasarela/certificacion/dte/envio';
    $token = env('token');

    $caratula = [
        "RUTEmisor" => "76974300-6",
        "RutReceptor" => "60803000-K",
        "FchResol" => "2014-08-22",
        "NroResol" => 0
    ];
    $emisor = [
        "RUTEmisor" => "76192083-9",
        "RznSoc" => "SASCO SpA",
        "GiroEmis" => "Servicios integrales de informática",
        "Acteco" => 726000,
        "DirOrigen" => "Santiago",
        "CmnaOrigen" => "Santiago"
    ];

    $body = [
        "Caratula" => $caratula,
        "Documentos" => [
            [
                "Encabezado" => [
                    "IdDoc" => [
                        "TipoDTE" => 39,
                        "Folio" => 101
                    ],
                    "Emisor" => [
                        "RUTEmisor" => "76974300-6",
                        "RznSoc" => "Logiciel Chile SA",
                        "GiroEmis" => "CONSULTORIAS, ASESORIAS, SERVICIOS DE INGENIERIA Y TELECOMUNICACIONES EXPORTACIO",
                        "Acteco" => 620100,
                        "DirOrigen" => "Av. Pedro de Valdivia 5841",
                        "CmnaOrigen" => "Macul"
                    ],
                    "Receptor" => [
                        "RUTRecep" => "000-0"
                    ]
                ],
                "Detalle" => [
                    [
                        "NmbItem" => "Cambio de aceite",
                        "QtyItem" => 1,
                        "PrcItem" => 19900
                    ],
                    [
                        "NmbItem" => "Alineacion y balanceo",
                        "QtyItem" => 1,
                        "PrcItem" => 9900
                    ]
                ]
            ]
        ],
        "Cafs" => [env('cafb64')],
        "firmab64" => env('firmab64'),
        "pswb64" => env('pswb64')
    ];
});

test('dte_33', function () use (&$url, &$token, &$caratula, &$emisor, &$body) {

    $result = stress($url)->post($body)->options()
        ->concurrency(2)
        ->for(10)->second()
        ->dump();

    expect($result->requests->failed->count)
        ->toBe(0);

    expect($result->requests->duration->med)
        ->toBe(6);

    /*
    $response = $this->postJson($url,
        [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]
    );

    // dump($response->getContent());
    $response->assertStatus(200)
        ->assertJsonStructure([
            'dte_xml',
            'pdfb64'
        ]);
    */
});

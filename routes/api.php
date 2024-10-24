<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserAuthController;

/*

|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Auth: obtener token
Route::post('/tokens/create', [UserAuthController::class, 'createToken']);

// Api/V1
Route::group(['prefix' => 'v1', 'namespace' => 'App\Http\Controllers\Api\V1', 'middleware' => ['auth:sanctum']], function () {
    // Pasarela
    // Envio de DTE y Boletas
    Route::post('pasarela/{ambiente}/dte/envio', 'ApiPasarelaController@generarDte');
    // Envio de DTE a Receptor
    Route::post('pasarela/dte/envio.receptor', 'ApiPasarelaController@generarDteReceptor');
    // Consulta de estado de envio
    Route::post('pasarela/{ambiente}/dte/estado.envio', 'ApiPasarelaController@estadoEnvio');
    // Consulta de estado de documento
    Route::post('pasarela/{ambiente}/dte/estado.documento', 'ApiPasarelaController@estadoDocumento');
    // Respuesta de Documento
    Route::post('pasarela/{ambiente}/dte/respuesta.documento', 'ApiPasarelaController@respuestaDocumento');
    // Importar Dtes Correo
    Route::post('pasarela/dtes/correos.importar', 'ApiPasarelaController@importarDtesCorreo');
    // Obtener CAF
    Route::post('pasarela/{ambiente}/caf.obtener', 'ApiPasarelaController@obtenerCaf');
    // Resumen Ventas Diarias
    Route::post('pasarela/{ambiente}/resumenVentas', 'ApiPasarelaController@resumenVentas');
    // Obtener Resumen/Detalle Registro Compra Venta
    Route::post('pasarela/{ambiente}/registroCompraVenta', 'ApiPasarelaController@obtenerRegistroCompraVenta');
    // Consultas Registro Compra Venta
    Route::post('pasarela/{ambiente}/registroCompraVenta/consulta', 'ApiPasarelaController@consultaRegistroCompraVenta');
    // Generar PDF a partir de un XML
    Route::post('pasarela/dtes/pdf', 'ApiPasarelaController@generarPdf');
    // Generar XML & PDF
    Route::post('pasarela/dtes/fakepdf', 'ApiPasarelaController@generarFakePDF');
});

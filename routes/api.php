<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
Route::post('/tokens/create', 'App\Http\Controllers\UserAuthController@login');

// Api/V1
Route::group(['prefix' => 'v1', 'namespace' => 'App\Http\Controllers\Api\V1', 'middleware' => ['auth:sanctum']], function () {

    // Caf
    Route::post('{ambiente}/subircaf', 'ApiFacturaController@subirCaf');
    Route::post('{ambiente}/subircaf.forzar', 'ApiFacturaController@forzarSubirCaf');

    // Rcof
    Route::post('rcof/{dte_filename}', 'ApiSetPruebaBEController@enviarRcofOnly');

    // Envio de Boletas
    Route::post('{ambiente}/boletas/envio', 'ApiBoletaController@boletaElectronica');
    Route::post('{ambiente}/boletas/estado.envio', 'ApiBoletaController@estadoDteEnviado');
    Route::post('{ambiente}/boletas/estado.dte', 'ApiBoletaController@estadoDte');

    // Envio de Factura
    Route::post('{ambiente}/dte/envio', 'ApiFacturaController@envioDte');
    Route::post('{ambiente}/dte/estado.envio', 'ApiFacturaController@estadoEnvioDte');
    Route::post('{ambiente}/dte/estado.dte', 'ApiFacturaController@estadoDte');
    Route::post('{ambiente}/dte/respuesta', 'ApiFacturaController@enviarRespuestaDocumento');

    // Envio de Set de Prueba
    Route::post('setdeprueba/envio', 'ApiSetPruebaBEController@setPrueba');
    Route::post('setdeprueba/estado.envio', 'ApiSetPruebaBEController@estadoDteEnviado');
    Route::post('setdeprueba/estado.dte', 'ApiSetPruebaBEController@estadoDte');

    // Administración
    Route::post('administrar/clientes/agregar', 'ApiFacturaController@agregarCliente');
});

<?php

use App\Http\Controllers\UserController;
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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/user/{id}', [UserController::class, 'show']);

// Api/V1
Route::group(['prefix' => 'v1', 'namespace' => 'App\Http\Controllers\Api\V1'], function () {
    Route::post('apicontroller', 'ApiController@respond');

    // Caf
    Route::post('subircaf', 'ApiSetPruebaBEController@subirCaf');
    Route::post('subircafforce', 'ApiSetPruebaBEController@forzarSubirCaf');

    // Rcof
    Route::post('rcof/{dte_filename}', 'ApiSetPruebaBEController@enviarRcofOnly');

    // Envio de Boletas
    Route::post('boletas/envio', 'BoletaController@boletaElectronica');
    Route::post('boletas/estado.envio', 'BoletaController@estadoDteEnviado');
    Route::post('boletas/estado.dte', 'BoletaController@estadoDte');

    // Envio de Set de Prueba
    Route::post('setdeprueba/envio', 'ApiSetPruebaBEController@setPrueba');
    Route::post('setdeprueba/estado.envio', 'ApiSetPruebaBEController@estadoDteEnviado');
    Route::post('setdeprueba/estado.dte', 'ApiSetPruebaBEController@estadoDte');
});

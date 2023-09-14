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
    Route::post('subircaf', 'SetPruebaController@subirCaf');
    Route::post('subircafforce', 'SetPruebaController@forzarSubirCaf');

    // Envio de Boletas
    Route::post('boletas/envio', 'BoletaController@index');
    Route::post('boletas/estado.envio', 'BoletaController@estadoDteEnviado');
    Route::post('boletas/estado.dte', 'BoletaController@estadoDte');

    // Envio de Set de Prueba
    Route::post('setdeprueba/envio', 'SetPruebaController@index');
    Route::post('setdeprueba/estado.envio', 'SetPruebaController@estadoDteEnviado');
    Route::post('setdeprueba/estado.dte', 'SetPruebaController@estadoDte');
    Route::post('setdeprueba/rcof', 'SetPruebaController@enviarRcofOnly');
});

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

    // Envio de Boletas
    Route::post('envioboleta', 'BoletaController@index');
    Route::post('estadoDteEnviado', 'BoletaController@estadoDteEnviado');
    Route::post('estadoDte', 'BoletaController@estadoDte');

    // Envio de Set de Prueba
    Route::post('enviosetdeprueba', 'SetPruebaController@index');
    Route::post('estadoSetEnviado', 'SetPruebaController@estadoDteEnviado');
    Route::post('estadoSet', 'SetPruebaController@estadoDte');
    Route::post('subirCaf', 'SetPruebaController@subirCaf');
});

<?php

use Illuminate\Http\Request;
use App\Payment;
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

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/', 'AmoKassaController@index');
Route::get('/test', function () {
	//$exitCode1 = Artisan::call('cache:clear');
	//print_r($exitCode1);
	//$exitCode2 = Artisan::call('config:clear');
	//print_r($exitCode2);
	return Payment::all();
});
Route::post('/amo', 'AmoKassaController@amo');
Route::post('/yandex/kassa_http/{status}', 'AmoKassaController@yandexHttp');
Route::post('/yandex/kassa_api', 'AmoKassaController@yandexAPI');
<?php

use App\Http\Controllers\Api\EcosystemMembershipController;
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

// InfoDot tells this app, signed, that a company's membership changed, so access ends at once.
// Authenticated by the signature, not by a session. See EcosystemMembershipController.
Route::post('/ecosystem/membership', EcosystemMembershipController::class)
    ->middleware('throttle:60,1')
    ->name('api.ecosystem.membership');

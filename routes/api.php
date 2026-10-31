<?php

use App\Http\Controllers\Api\EcosystemMembershipController;
use App\Http\Controllers\Api\EcosystemSearchController;
use App\Http\Controllers\Api\PlatformFileController;
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

// InfoDot's header search asks which of a company's files match. Signed, answers only members.
Route::post('/ecosystem/search', EcosystemSearchController::class)
    ->middleware('throttle:120,1')
    ->name('api.ecosystem.search');

// The platform file API (R-25 Phase 3): other Dot platforms store and fetch business files
// here, server to server, each signed with its own secret and scoped to its own files.
// Contract: docs/programme/PLATFORM_FILE_API.md.
Route::prefix('/platform/files')->name('api.platform.files.')->group(function () {
    Route::post('/store', [PlatformFileController::class, 'store'])
        ->middleware('throttle:60,1')->name('store');
    Route::post('/update', [PlatformFileController::class, 'update'])
        ->middleware('throttle:60,1')->name('update');
    Route::post('/fetch', [PlatformFileController::class, 'fetch'])
        ->middleware('throttle:120,1')->name('fetch');
    Route::post('/meta', [PlatformFileController::class, 'meta'])
        ->middleware('throttle:120,1')->name('meta');
    Route::post('/list', [PlatformFileController::class, 'list'])
        ->middleware('throttle:120,1')->name('list');
    Route::post('/trash', [PlatformFileController::class, 'trash'])
        ->middleware('throttle:60,1')->name('trash');
});

<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\DeletionModerationController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FileOpenController;
use App\Http\Controllers\SharedFileController;
use App\Models\File;
use App\Services\FileShareService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth:sanctum', 'verified'])->get('/dashboard', function () {
    return view('dashboard');
})->name('dashboard');

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->name('auth.ecosystem');

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively (registered at /terms-of-service and /privacy-policy, reading resources/markdown/
// terms.md and policy.md). There's no Jetstream equivalent for a Cookie Policy, so this one is
// wired by hand, following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/files', [FileController::class, 'index'])->name('files');
    Route::get('/files/{file}', [FileController::class, 'download'])->name('files.download');
    // The destination of an InfoDot deep link: a file, by uuid. Re-authorised here.
    Route::get('/o/{uuid}', FileOpenController::class)->name('files.open');

    // Cross-company sharing: the company side manages a file's shares; the other side sees what
    // was shared with them. Every request re-checks the share against the verified email.
    Route::get('/files/{file}/share', function (File $file) {
        abort_unless(app(FileShareService::class)->canShare(auth()->user(), $file), 403);

        return view('files.share', ['file' => $file]);
    })->name('files.share');
    Route::get('/shared', [SharedFileController::class, 'index'])->name('shared');
    Route::get('/shared/{share}/download', [SharedFileController::class, 'download'])->name('shared.download');
});

Route::middleware(['auth', 'verified', 'operator'])->prefix('operator')->name('operator.')->group(function () {
    Route::get('/deletion-requests', [DeletionModerationController::class, 'index'])->name('deletion-requests.index');
    Route::post('/deletion-requests/team/{teamDeletionRequest}/approve', [DeletionModerationController::class, 'approveTeam'])->name('deletion-requests.team.approve');
    Route::post('/deletion-requests/team/{teamDeletionRequest}/reject', [DeletionModerationController::class, 'rejectTeam'])->name('deletion-requests.team.reject');
    Route::post('/deletion-requests/bulk/{bulkDeletionRequest}/approve', [DeletionModerationController::class, 'approveBulk'])->name('deletion-requests.bulk.approve');
    Route::post('/deletion-requests/bulk/{bulkDeletionRequest}/reject', [DeletionModerationController::class, 'rejectBulk'])->name('deletion-requests.bulk.reject');
});

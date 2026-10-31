<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DeletionModerationController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FileOpenController;
use App\Http\Controllers\ShareApprovalController;
use App\Http\Controllers\SharedFileController;
use App\Models\File;
use App\Services\FileShareService;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Fortify\Features;
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

// The workspace IS the product: anything pointing at the old Jetstream dashboard lands in the room.
Route::middleware(['auth:sanctum', 'verified'])->get('/dashboard', function () {
    return redirect()->route('files');
})->name('dashboard');

Route::post('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])->name('auth.ecosystem');

// Links minted before the token moved into a POST body carried it in the URL. They are no longer honoured:
// the visitor is sent to the normal sign-in page and the token in the URL is never read.
Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'legacy']);

// CSP violation reports (browser-sent, session-less; authenticity is irrelevant — it only ever
// logs a directive and a host, throttled and de-duplicated). R-25 fleet pattern.
Route::post('/csp-report', CspReportController::class)
    ->middleware('throttle:60,1')
    ->withoutMiddleware([
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        StartSession::class,
        ShareErrorsFromSession::class,
    ])->name('csp-report');

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
    Route::get('/files/{file}/preview', [FileController::class, 'preview'])->name('files.preview');
    Route::get('/files/{file}', [FileController::class, 'download'])->name('files.download');
    // The destination of an InfoDot deep link: a file, by uuid. Re-authorised here.
    Route::get('/o/{uuid}', FileOpenController::class)->name('files.open');

    // Cross-company sharing: owners/admins manage a file's shares; any other member of the
    // company may ASK for one (R-26) — the request grants nothing until it is approved.
    // Every use of a share is re-checked against the recipient's verified email.
    Route::get('/files/{file}/share', function (File $file) {
        $service = app(FileShareService::class);
        $user = auth()->user();

        abort_unless($service->canShare($user, $file) || $service->canRequestShare($user, $file), 403);

        return view('files.share', ['file' => $file, 'canShare' => $service->canShare($user, $file)]);
    })->name('files.share');
    Route::post('/files/{file}/share-request', [ShareApprovalController::class, 'store'])->name('files.share-request');
    Route::get('/shared', [SharedFileController::class, 'index'])->name('shared');
    Route::get('/shared/{share}/download', [SharedFileController::class, 'download'])->name('shared.download');
    Route::get('/shared/{share}/preview', [SharedFileController::class, 'preview'])->name('shared.preview');

    // Sharing approvals (R-26): members ask, owners and administrators decide. The decision is
    // enforced server-side in FileShareService — a pending share grants nothing anywhere.
    Route::get('/approvals', [ShareApprovalController::class, 'index'])->name('approvals');
    Route::post('/approvals/{share}/approve', [ShareApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('/approvals/{share}/decline', [ShareApprovalController::class, 'decline'])->name('approvals.decline');
});

Route::middleware(['auth', 'verified', 'operator'])->prefix('operator')->name('operator.')->group(function () {
    Route::get('/deletion-requests', [DeletionModerationController::class, 'index'])->name('deletion-requests.index');
    Route::post('/deletion-requests/team/{teamDeletionRequest}/approve', [DeletionModerationController::class, 'approveTeam'])->name('deletion-requests.team.approve');
    Route::post('/deletion-requests/team/{teamDeletionRequest}/reject', [DeletionModerationController::class, 'rejectTeam'])->name('deletion-requests.team.reject');
    Route::post('/deletion-requests/bulk/{bulkDeletionRequest}/approve', [DeletionModerationController::class, 'approveBulk'])->name('deletion-requests.bulk.approve');
    Route::post('/deletion-requests/bulk/{bulkDeletionRequest}/reject', [DeletionModerationController::class, 'rejectBulk'])->name('deletion-requests.bulk.reject');
});

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}

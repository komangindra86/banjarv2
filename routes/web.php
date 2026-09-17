<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\KramaController;
use App\Http\Controllers\LetterController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PublicWebsiteController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\WebsiteAdminController;
use App\Http\Controllers\WithdrawalController;
use App\Modules\Website\ContentCatalog;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicWebsiteController::class, 'home']);
Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login');
Route::post('/logout', [AuthController::class, 'destroy']);
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest']);

Route::post('/webhooks/midtrans', [PaymentController::class, 'webhook']);
Route::middleware(['access:tenant', 'module:PAYMENT', 'module:FINANCE', 'module:KRAMA'])->group(function () {
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments/bills/{id}', [PaymentController::class, 'create'])->whereNumber('id')->middleware('throttle:10,1');
    Route::get('/payments/{id}', [PaymentController::class, 'show'])->whereNumber('id');
    Route::post('/payments/{id}/refresh', [PaymentController::class, 'refresh'])->whereNumber('id')->middleware('throttle:10,1');
    Route::get('/admin/payments', [PaymentController::class, 'ledger']);
    Route::prefix('admin/withdrawals')->group(function () {
        Route::get('/', [WithdrawalController::class, 'index']);
        Route::post('/', [WithdrawalController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/accounts', [WithdrawalController::class, 'accounts']);
        Route::post('/accounts', [WithdrawalController::class, 'storeAccount'])->middleware('throttle:5,1');
        Route::patch('/policy', [WithdrawalController::class, 'policy']);
        Route::get('/{id}', [WithdrawalController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/approve', [WithdrawalController::class, 'approve'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('/{id}/cancel', [WithdrawalController::class, 'cancel'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('/{id}/proof', [WithdrawalController::class, 'proof'])->whereNumber('id');
    });
});

Route::middleware(['access:tenant', 'module:ACTIVITY,ACTIVE', 'module:KRAMA'])->group(function () {
    Route::get('/aktivitas', [ActivityController::class, 'portal']);
    Route::post('/aktivitas/{id}/izin', [ActivityController::class, 'excuse'])->whereNumber('id')->middleware('throttle:10,1');
});

Route::prefix('portal')->middleware(['access:tenant', 'module:ADMINISTRATION', 'module:KRAMA'])->group(function () {
    Route::get('/', [LetterController::class, 'index']);
    Route::get('/layanan', [LetterController::class, 'services']);
    Route::get('/layanan/{id}/ajukan', [LetterController::class, 'create'])->whereNumber('id');
    Route::get('/surat', [LetterController::class, 'index']);
    Route::post('/surat', [LetterController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/surat/{id}', [LetterController::class, 'show'])->whereNumber('id');
    Route::get('/surat/{id}/pdf', [LetterController::class, 'download'])->whereNumber('id');
    Route::post('/surat/{id}/cancel', [LetterController::class, 'cancel'])->whereNumber('id');
});

Route::prefix('platform')->middleware('access:platform')->group(function () {
    Route::get('/payments', [PaymentController::class, 'platform']);
    Route::get('/payments/{id}', [PaymentController::class, 'evidence'])->whereNumber('id');
    Route::post('/payments/{id}/evidence', [PaymentController::class, 'submitEvidence'])->whereNumber('id');
    Route::post('/payments/{id}/approve', [PaymentController::class, 'approveEvidence'])->whereNumber('id');
    Route::get('/withdrawals', [WithdrawalController::class, 'platform']);
    Route::get('/withdrawals/{id}', [WithdrawalController::class, 'platformShow'])->whereNumber('id');
    Route::post('/withdrawals/{id}/claim', [WithdrawalController::class, 'claim'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/withdrawals/{id}/reveal', [WithdrawalController::class, 'reveal'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/withdrawals/{id}/paid', [WithdrawalController::class, 'paid'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/withdrawals/{id}/failed', [WithdrawalController::class, 'failed'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/withdrawals/{id}/cancel', [WithdrawalController::class, 'platformCancel'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('/withdrawals/{id}/proof', [WithdrawalController::class, 'platformProof'])->whereNumber('id');
    Route::post('/bank-accounts/{id}/review', [WithdrawalController::class, 'reviewAccount'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/bank-accounts/{id}/reveal', [WithdrawalController::class, 'revealAccount'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('/banjars/{id}/members', [MembershipController::class, 'index'])->whereNumber('id');
    Route::post('/banjars/{id}/members', [MembershipController::class, 'store'])->whereNumber('id');
    Route::patch('/banjars/{id}/members/{membership}', [MembershipController::class, 'update'])->whereNumber(['id', 'membership']);
    Route::get('/', [PlatformController::class, 'index']);
    Route::get('/banjars/create', [PlatformController::class, 'create']);
    Route::post('/banjars', [PlatformController::class, 'store']);
    Route::get('/banjars/{id}', [PlatformController::class, 'show'])->whereNumber('id');
    Route::patch('/banjars/{id}/status', [PlatformController::class, 'status'])->whereNumber('id');
    Route::patch('/banjars/{id}/modules/{code}', [PlatformController::class, 'module'])->whereNumber('id');
    Route::get('/audit', [PlatformController::class, 'audit']);
});
Route::prefix('admin')->middleware('access:tenant')->group(function () {
    Route::prefix('finance')->middleware(['module:FINANCE', 'module:KRAMA'])->group(function () {
        Route::get('/', [FinanceController::class, 'index']);
        Route::get('/settings', [FinanceController::class, 'settings']);
        Route::post('/accounts', [FinanceController::class, 'storeAccount']);
        Route::post('/types', [FinanceController::class, 'storeType']);
        Route::post('/types/{id}/toggle', [FinanceController::class, 'toggleType'])->whereNumber('id');
        Route::patch('/policy', [FinanceController::class, 'policy']);
        Route::get('/events/create', [FinanceController::class, 'createEvent']);
        Route::post('/events', [FinanceController::class, 'storeEvent'])->middleware('throttle:30,1');
        Route::get('/events/{id}', [FinanceController::class, 'event'])->whereNumber('id');
        Route::post('/events/{id}/subjects', [FinanceController::class, 'subject'])->whereNumber('id');
        Route::delete('/events/{id}/subjects/{subject}', [FinanceController::class, 'removeSubject'])->whereNumber(['id', 'subject']);
        Route::post('/events/{id}/review', [FinanceController::class, 'review'])->whereNumber('id');
        Route::post('/events/{id}/issue', [FinanceController::class, 'issue'])->whereNumber('id')->middleware('throttle:10,1');
        Route::post('/events/{id}/void', [FinanceController::class, 'void'])->whereNumber('id');
        Route::get('/bills', [FinanceController::class, 'bills']);
        Route::get('/bills/{id}', [FinanceController::class, 'bill'])->whereNumber('id');
        Route::post('/bills/{id}/settle', [FinanceController::class, 'settle'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('/cash', [FinanceController::class, 'cash']);
        Route::post('/cash', [FinanceController::class, 'storeCash'])->middleware('throttle:30,1');
        Route::post('/cash/{id}/reverse', [FinanceController::class, 'reverse'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('/report', [FinanceController::class, 'report']);
    });
    Route::prefix('activities')->middleware(['module:ACTIVITY,ACTIVE', 'module:KRAMA'])->group(function () {
        Route::get('/', [ActivityController::class, 'index']);
        Route::get('/{id}', [ActivityController::class, 'show'])->whereNumber('id');
        Route::patch('/{id}/settings', [ActivityController::class, 'settings'])->whereNumber('id');
        Route::post('/{id}/targets', [ActivityController::class, 'addTarget'])->whereNumber('id');
        Route::delete('/{id}/targets/{target}', [ActivityController::class, 'removeTarget'])->whereNumber(['id', 'target']);
        Route::post('/{id}/participants', [ActivityController::class, 'generate'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('/{id}/attendance', [ActivityController::class, 'attendance'])->whereNumber('id');
        Route::post('/{id}/attendance/remaining', [ActivityController::class, 'remaining'])->whereNumber('id');
        Route::post('/{id}/attendance/{participant}', [ActivityController::class, 'record'])->whereNumber(['id', 'participant'])->middleware('throttle:240,1');
        Route::post('/{id}/verify', [ActivityController::class, 'verify'])->whereNumber('id');
        Route::get('/{id}/dedosan', [ActivityController::class, 'dedosan'])->whereNumber('id');
        Route::post('/{id}/dedosan/event', [ActivityController::class, 'createEvent'])->whereNumber('id')->middleware('throttle:10,1');
        Route::post('/{id}/dedosan/{candidate}', [ActivityController::class, 'decide'])->whereNumber(['id', 'candidate']);
        Route::get('/{id}/minutes', [ActivityController::class, 'minutes'])->whereNumber('id');
        Route::post('/{id}/minutes', [ActivityController::class, 'saveMinutes'])->whereNumber('id');
        Route::post('/{id}/minutes/finalize', [ActivityController::class, 'finalize'])->whereNumber('id');
        Route::post('/{id}/minutes/attachments', [ActivityController::class, 'attach'])->whereNumber('id');
        Route::get('/{id}/minutes/attachments/{attachment}', [ActivityController::class, 'attachment'])->whereNumber(['id', 'attachment']);
        Route::delete('/{id}/minutes/attachments/{attachment}', [ActivityController::class, 'detach'])->whereNumber(['id', 'attachment']);
    });
    Route::prefix('letters')->middleware(['module:ADMINISTRATION', 'module:KRAMA'])->group(function () {
        Route::get('/', [LetterController::class, 'index']);
        Route::get('/services', [LetterController::class, 'services']);
        Route::patch('/services/{id}', [LetterController::class, 'configure'])->whereNumber('id');
        Route::get('/{id}', [LetterController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/review', [LetterController::class, 'review'])->whereNumber('id')->middleware('throttle:30,1');
        Route::post('/{id}/retry', [LetterController::class, 'retry'])->whereNumber('id')->middleware('throttle:5,1');
        Route::get('/{id}/pdf', [LetterController::class, 'download'])->whereNumber('id');
    });
    Route::prefix('krama')->middleware('module:KRAMA')->group(function () {
        Route::get('/', [KramaController::class, 'index']);
        Route::get('/mine', [KramaController::class, 'mine']);
        Route::get('/units', [KramaController::class, 'units']);
        Route::post('/units', [KramaController::class, 'saveUnit']);
        Route::patch('/units/{id}', [KramaController::class, 'saveUnit'])->whereNumber('id');
        Route::patch('/policy', [KramaController::class, 'policy']);
        Route::get('/households/create', [KramaController::class, 'household']);
        Route::post('/households', [KramaController::class, 'saveHousehold']);
        Route::get('/households/{id}', [KramaController::class, 'household'])->whereNumber('id');
        Route::patch('/households/{id}', [KramaController::class, 'saveHousehold'])->whereNumber('id');
        Route::get('/people/create', [KramaController::class, 'person']);
        Route::post('/people', [KramaController::class, 'savePerson']);
        Route::get('/people/{id}', [KramaController::class, 'person'])->whereNumber('id');
        Route::patch('/people/{id}', [KramaController::class, 'savePerson'])->whereNumber('id');
        Route::get('/people/{id}/change', [KramaController::class, 'changeForm'])->whereNumber('id');
        Route::post('/people/{id}/change', [KramaController::class, 'submitChange'])->whereNumber('id');
        Route::get('/changes', [KramaController::class, 'changes']);
        Route::get('/changes/{id}', [KramaController::class, 'change'])->whereNumber('id');
        Route::post('/changes/{id}/review', [KramaController::class, 'review'])->whereNumber('id');
    });
    Route::get('/profile', [WebsiteAdminController::class, 'profile']);
    Route::patch('/profile', [WebsiteAdminController::class, 'updateProfile']);
    Route::get('/media', [WebsiteAdminController::class, 'media']);
    Route::post('/media', [WebsiteAdminController::class, 'upload']);
    Route::patch('/media/{id}', [WebsiteAdminController::class, 'visibility'])->whereNumber('id');
    Route::get('/media/{id}/download', [WebsiteAdminController::class, 'download'])->whereNumber('id');
    Route::get('/qr', [WebsiteAdminController::class, 'qr']);
    Route::get('/content/{type}', [WebsiteAdminController::class, 'index']);
    Route::get('/content/{type}/create', [WebsiteAdminController::class, 'edit']);
    Route::post('/content/{type}', [WebsiteAdminController::class, 'save']);
    Route::get('/content/{type}/{id}/edit', [WebsiteAdminController::class, 'edit'])->whereNumber('id');
    Route::patch('/content/{type}/{id}', [WebsiteAdminController::class, 'save'])->whereNumber('id');
    Route::delete('/content/{type}/{id}', [WebsiteAdminController::class, 'destroy'])->whereNumber('id');
    Route::get('/', [AdminController::class, 'index']);
    Route::get('/website', [AdminController::class, 'website'])->middleware(['access:tenant,website.manage', 'module:WEBSITE']);
    Route::get('/activity', [AdminController::class, 'activity'])->middleware(['access:tenant,activity.manage', 'module:ACTIVITY']);
    Route::get('/settings', [AdminController::class, 'settings'])->middleware(['access:tenant,settings.manage', 'module:WEBSITE']);
    Route::patch('/settings/{id}', [AdminController::class, 'update'])->whereNumber('id')->middleware(['access:tenant,settings.manage', 'module:WEBSITE']);
    Route::get('/audit', [AdminController::class, 'audit'])->middleware('access:tenant,audit.view');
});

Route::get('/profil', [PublicWebsiteController::class, 'profile']);
Route::get('/media/{id}', [PublicWebsiteController::class, 'media'])->whereNumber('id');
Route::get('/sitemap.xml', [PublicWebsiteController::class, 'sitemap']);
Route::get('/{type}', [PublicWebsiteController::class, 'index'])->whereIn('type', array_keys(ContentCatalog::TYPES));
Route::get('/{type}/{slug}', [PublicWebsiteController::class, 'show'])->whereIn('type', array_keys(ContentCatalog::TYPES));

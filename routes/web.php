<?php
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('oauth/{client}/authorize', [OAuthController::class, 'authorizeClient'])
    ->middleware('auth')->block(60, 10)->name('oauth.authorize');
Route::get('token/{id}', [OAuthController::class, 'token'])
    ->middleware('auth')->block(60, 10)->name('oauth.callback');
Route::post('webhook/{client_id}/{interface}', [WebhookController::class, 'handle']);

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
//    Route::get('/test-storage', function () { return Storage::disk('public')->directories('clients'); });
});

require __DIR__.'/auth.php';

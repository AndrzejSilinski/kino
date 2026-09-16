<?php

use App\Http\Controllers\Admin\Auth\LoginController;
use App\Livewire\Admin\Dashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Panel administracyjny (Etap 7)
|--------------------------------------------------------------------------
| Grupa middleware 'web' (sesja, ciasteczka, CSRF) jest nadawana plikowi
| automatycznie. auth + can:panel.access to BRAMKA: wpuszcza administratora
| i obsługę kina. Dostęp do konkretnych zasobów sprawdzają Policies.
|
| Logowanie bez throttle w middleware: nieudane próby liczy PanelAuthService
| (udane nie zjadają limitu, a błąd wraca jako komunikat formularza).
*/
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    });

    Route::middleware(['auth', 'can:panel.access'])->group(function (): void {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
        Route::livewire('/', Dashboard::class)->name('dashboard');
    });
});

<?php

use App\Http\Controllers\Admin\Auth\LoginController;
use App\Livewire\Admin\Bookings\BookingIndex;
use App\Livewire\Admin\Bookings\BookingShow;
use App\Livewire\Admin\Cinemas\CinemaForm;
use App\Livewire\Admin\Cinemas\CinemaIndex;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Halls\HallForm;
use App\Livewire\Admin\Halls\HallIndex;
use App\Livewire\Admin\Halls\HallLayoutEditor;
use App\Livewire\Admin\Movies\MovieForm;
use App\Livewire\Admin\Movies\MovieIndex;
use App\Livewire\Admin\Screenings\RepertoireCopy;
use App\Livewire\Admin\Screenings\ScreeningForm;
use App\Livewire\Admin\Screenings\ScreeningSeatPlan;
use App\Livewire\Admin\Screenings\ScreeningWeek;
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

        // Struktura kin (blok D) — wyłącznie administrator. can: na trasie to bramka;
        // komponenty sprawdzają te same Policies jeszcze raz (Livewire::test i żądania
        // aktualizacji komponentu nie przechodzą przez middleware tej trasy).
        Route::livewire('/cinemas', CinemaIndex::class)
            ->name('cinemas.index')->middleware('can:viewAny,App\Models\Cinema');
        Route::livewire('/cinemas/create', CinemaForm::class)
            ->name('cinemas.create')->middleware('can:create,App\Models\Cinema');
        Route::livewire('/cinemas/{cinema}/edit', CinemaForm::class)
            ->name('cinemas.edit')->middleware('can:update,cinema');
        Route::livewire('/cinemas/{cinema}/halls', HallIndex::class)
            ->name('cinemas.halls.index')->middleware('can:viewAny,App\Models\Hall');
        Route::livewire('/cinemas/{cinema}/halls/create', HallForm::class)
            ->name('cinemas.halls.create')->middleware('can:create,App\Models\Hall');
        Route::livewire('/halls/{hall}/edit', HallForm::class)
            ->name('halls.edit')->middleware('can:update,hall');
        Route::livewire('/halls/{hall}/layout', HallLayoutEditor::class)
            ->name('halls.layout')->middleware('can:update,hall');

        // Filmy i plakaty (blok F) — wyłącznie administrator; film jest wspólny dla całej sieci.
        Route::livewire('/movies', MovieIndex::class)
            ->name('movies.index')->middleware('can:viewAny,App\Models\Movie');
        Route::livewire('/movies/create', MovieForm::class)
            ->name('movies.create')->middleware('can:create,App\Models\Movie');
        Route::livewire('/movies/{movie}/edit', MovieForm::class)
            ->name('movies.edit')->middleware('can:update,movie');

        // Repertuar (blok G2): siatkę kina widzi administrator i obsługa tego kina,
        // planuje wyłącznie administrator.
        Route::livewire('/cinemas/{cinema}/screenings', ScreeningWeek::class)
            ->name('cinemas.screenings.index')->middleware('can:viewRepertoire,cinema');
        Route::livewire('/cinemas/{cinema}/screenings/create', ScreeningForm::class)
            ->name('cinemas.screenings.create')->middleware('can:create,App\Models\Screening');
        Route::livewire('/screenings/{screening}/edit', ScreeningForm::class)
            ->name('screenings.edit')->middleware('can:update,screening');
        // Kopiowanie repertuaru z dnia na dzień (blok H) — planowanie, więc tylko administrator.
        Route::livewire('/cinemas/{cinema}/screenings/copy', RepertoireCopy::class)
            ->name('cinemas.screenings.copy')->middleware('can:create,App\Models\Screening');

        // Sprzedaż (blok I): administrator i obsługa kina; zakres obsługi pilnują
        // Policies i zapytania komponentów.
        Route::livewire('/bookings', BookingIndex::class)
            ->name('bookings.index')->middleware('can:viewAnyInPanel,App\Models\Booking');
        Route::livewire('/bookings/{booking}', BookingShow::class)
            ->name('bookings.show')->middleware('can:viewInPanel,booking');
        Route::livewire('/screenings/{screening}/seats', ScreeningSeatPlan::class)
            ->name('screenings.seats')->middleware('can:viewSeatPlan,screening');
    });
});

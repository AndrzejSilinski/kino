<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Auth;

use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\PanelLoginThrottledException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PanelLoginRequest;
use App\Services\PanelAuthService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Logowanie i wylogowanie w panelu (Etap 7, blok B3).
 *
 * Kontroler robi wyłącznie rzeczy HTTP: przyjmuje formularz, woła serwis,
 * obsługuje sesję i przekierowania. Wyjątki domenowe z serwisu zamienia
 * na błąd formularza — ApiExceptionRenderer celowo pomija trasy spoza
 * /api, więc bez tego użytkownik zobaczyłby stronę błędu 500.
 */
final class LoginController extends Controller
{
    public function __construct(
        private readonly PanelAuthService $auth,
    ) {}

    public function create(): View
    {
        return view('admin.auth.login');
    }

    public function store(PanelLoginRequest $request): RedirectResponse
    {
        try {
            $this->auth->login(
                (string) $request->validated('email'),
                (string) $request->validated('password'),
                (string) $request->ip(),
            );
        } catch (InvalidCredentialsException|PanelLoginThrottledException $e) {
            // Do formularza wraca tylko e-mail, nigdy hasło.
            return back()->withErrors(['email' => $e->getMessage()])->onlyInput('email');
        }

        // Nowy identyfikator sesji po zalogowaniu: ochrona przed session
        // fixation (napastnik nie podsunie ofierze znanego sobie ID sesji).
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        // invalidate(): sesja znika z Redisa, a nie tylko z ciasteczka.
        // regenerateToken(): nowy token CSRF, stare formularze są bezużyteczne.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}

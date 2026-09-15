<?php

declare(strict_types=1);

namespace App\Providers;

use App\Payments\PaymentGateway;
use App\Payments\StripePaymentGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Wiązanie bramki płatności.
 *
 * singleton, bo adapter jest bezstanowy, a tworzenie klienta HTTP przy
 * każdym wstrzyknięciu byłoby marnotrawstwem.
 *
 * Domknięcie jest LENIWE: adapter powstaje dopiero przy pierwszym użyciu.
 * Dzięki temu test, który podmienia bramkę na atrapę, nigdy nie tworzy
 * klienta Stripe'a i nie potrzebuje żadnego klucza.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function (): PaymentGateway {
            /** @var array<string, mixed> $stripe */
            $stripe = config('payments.stripe');

            return new StripePaymentGateway($stripe);
        });
    }
}

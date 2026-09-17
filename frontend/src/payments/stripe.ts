/*
 * Formularz płatności Stripe (Etap 8, blok H3) — cienka warstwa nad Stripe.js i Payment Element.
 *
 * DLACZEGO Payment Element, a nie własne pola karty: dane karty wpisuje się w ramkach (iframe) z domeny
 * Stripe'a, więc nigdy nie przechodzą przez nasz kod ani serwer (zakres PCI SAQ A). Ten sam element
 * pokazuje metody włączone na koncie (karta, BLIK, Link, Klarna) i sam prowadzi 3-D Secure.
 *
 * DLACZEGO "@stripe/stripe-js/pure": główny import wstawia skrypt Stripe'a już przy imporcie modułu,
 * a "pure" dopiero przy loadStripe(). Razem z dynamicznym import() skrypt z js.stripe.com ładuje się
 * tylko na ekranie płatności, a nie na każdej stronie SPA.
 *
 * redirect: 'if_required' — karta i BLIK kończą się bez opuszczania strony; metody z przekierowaniem
 * (Klarna) wracają na return_url, gdzie stan bierzemy z serwera, a nie z parametrów adresu.
 *
 * Reszta aplikacji zna tylko interfejs PaymentUi — testy podstawiają atrapę bez sieci.
 */
import type { Stripe, StripeElements, StripePaymentElement } from '@stripe/stripe-js';

export type ConfirmOutcome =
  | { kind: 'confirmed'; status: string }
  | { kind: 'failed'; message: string; code: string | null };

export interface PaymentUi {
  /** Montuje formularz; kończy się, gdy jest gotowy do wpisywania (albo rzuca przy błędzie ładowania). */
  mount(element: HTMLElement): Promise<void>;
  confirm(returnUrl: string): Promise<ConfirmOutcome>;
  destroy(): void;
}

export interface PaymentUiOptions {
  publishableKey: string;
  clientSecret: string;
  dark: boolean;
}

/** Statusy PaymentIntentu po potwierdzeniu, przy których o wyniku rozstrzyga już webhook. */
const CONFIRMED = new Set(['requires_capture', 'succeeded', 'processing']);

const GENERIC_FAILURE = 'Płatność nie powiodła się. Sprawdź dane i spróbuj ponownie albo wybierz inną metodę.';

/**
 * Komunikat Stripe'a pokazujemy tylko dla błędów, które dokumentacja przeznacza dla klienta
 * (card_error, validation_error) — przychodzą już po polsku (locale: 'pl'). Inne typy (błąd API,
 * limit żądań) mogą zawierać szczegóły techniczne, więc dostają ogólny tekst.
 */
export function outcomeOf(result: { error?: { type: string; message?: string; code?: string }; paymentIntent?: { status: string } }): ConfirmOutcome {
  if (result.error) {
    const forCustomer = result.error.type === 'card_error' || result.error.type === 'validation_error';
    return {
      kind: 'failed',
      message: forCustomer && result.error.message ? result.error.message : GENERIC_FAILURE,
      code: result.error.code ?? null,
    };
  }
  const status = result.paymentIntent?.status ?? 'unknown';
  if (CONFIRMED.has(status)) {
    return { kind: 'confirmed', status };
  }
  return { kind: 'failed', message: GENERIC_FAILURE, code: status };
}

export async function createStripePaymentUi(options: PaymentUiOptions): Promise<PaymentUi> {
  const { loadStripe } = await import('@stripe/stripe-js/pure');
  const stripe: Stripe | null = await loadStripe(options.publishableKey, { locale: 'pl' });
  if (!stripe) {
    throw new Error('Stripe.js niedostępny.');
  }

  const elements: StripeElements = stripe.elements({
    clientSecret: options.clientSecret,
    locale: 'pl',
    appearance: { theme: options.dark ? 'night' : 'stripe' },
  });
  let element: StripePaymentElement | null = null;

  return {
    mount(node) {
      return new Promise<void>((resolve, reject) => {
        element = elements.create('payment', { layout: 'tabs' });
        element.once('ready', () => resolve());
        element.once('loaderror', (event) => reject(new Error(event.error.message ?? 'Formularz płatności się nie wczytał.')));
        element.mount(node);
      });
    },
    async confirm(returnUrl) {
      return outcomeOf(await stripe.confirmPayment({ elements, confirmParams: { return_url: returnUrl }, redirect: 'if_required' }));
    },
    destroy() {
      element?.destroy();
      element = null;
    },
  };
}

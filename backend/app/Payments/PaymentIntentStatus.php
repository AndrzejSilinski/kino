<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Status płatności u dostawcy.
 *
 * Wartości odpowiadają statusom PaymentIntentu Stripe'a, ale enum należy
 * do NASZEJ domeny — drugi dostawca mapowałby swoje statusy na te same
 * przypadki. Unknown istnieje dlatego, że Stripe może kiedyś dodać nowy
 * status: chcemy wtedy dostać "nie wiem" i zignorować zdarzenie, a nie
 * ValueError w środku obsługi webhooka.
 */
enum PaymentIntentStatus: string
{
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresConfirmation = 'requires_confirmation';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case RequiresCapture = 'requires_capture';
    case Succeeded = 'succeeded';
    case Canceled = 'canceled';
    case Unknown = 'unknown';

    public static function fromProvider(?string $status): self
    {
        return self::tryFrom((string) $status) ?? self::Unknown;
    }

    /** Pieniądze są zablokowane u klienta i czekają na pobranie. */
    public function isAuthorized(): bool
    {
        return $this === self::RequiresCapture;
    }

    /** Płatność można jeszcze anulować, czyli zwolnić bez zwrotu. */
    public function isCancelable(): bool
    {
        return in_array($this, [
            self::RequiresPaymentMethod,
            self::RequiresConfirmation,
            self::RequiresAction,
            self::RequiresCapture,
        ], true);
    }
}

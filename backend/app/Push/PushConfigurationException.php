<?php

declare(strict_types=1);

namespace App\Push;

use RuntimeException;

/**
 * Brak albo zła konfiguracja FCM (plik konta serwisowego, projekt). Komunikat nigdy nie zawiera
 * treści pliku ani klucza — co najwyżej nazwę brakującego pola.
 */
final class PushConfigurationException extends RuntimeException {}

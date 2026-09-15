<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Admin = 'admin';
    case Staff = 'staff';         // obsługa kina: skanuje bilety w przypisanym kinie
}

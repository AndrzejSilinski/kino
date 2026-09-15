<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rola obsługi kina (staff) przypisana do jednego kina.
 *
 * Pracownik obsługi skanuje bilety przy wejściu na salę, ale tylko w swoim
 * kinie. Przypisanie trzyma kolumna users.cinema_id, a spójność roli
 * z kinem pilnuje BAZA, nie kod PHP:
 *
 *   users_staff_has_cinema: (role = 'staff') = (cinema_id IS NOT NULL)
 *
 * czyli staff MUSI mieć kino, a klient i administrator NIE MOGĄ go mieć.
 * Równość dwóch warunków logicznych zapisuje obie reguły w jednym CHECK.
 *
 * restrictOnDelete, a nie nullOnDelete: wyzerowanie cinema_id pracownikowi
 * złamałoby powyższy CHECK. Kina i tak się nie usuwa, tylko wyłącza
 * (cinemas.is_active).
 *
 * Jedno kino na pracownika, a nie tabela pośrednia: tak wygląda praca
 * bramkarza, a policy sprowadza się do jednego porównania. Przejście na
 * tabelę pośrednią przy pracy w wielu kinach jest opisane w README.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('cinema_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('cinema_id');
        });

        // CHECK w PostgreSQL nie da się zmienić w miejscu — usuwamy i zakładamy
        // na nowo. Migracja w PostgreSQL wykonuje się w jednej transakcji,
        // więc nie ma chwili, w której tabela zostaje bez ograniczenia.
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('customer', 'admin', 'staff'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_staff_has_cinema CHECK ((role = 'staff') = (cinema_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_staff_has_cinema');

        // Stary CHECK nie dopuszcza roli staff. Konta nie usuwamy (ślad
        // walidacji biletów w tickets.validated_by_user_id), tylko cofamy rolę.
        DB::statement("UPDATE users SET role = 'customer' WHERE role = 'staff'");
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('customer', 'admin'))");

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cinema_id');
        });
    }
};

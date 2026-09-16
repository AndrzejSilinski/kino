<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Artykuły modułu informacyjnego (Etap 7, blok M; wymóg 2.4).
 *
 * type         — news ("Aktualności") albo premiere ("Nadchodzące premiery").
 * status       — draft albo published. "Zaplanowany" to published z published_at
 *                w przyszłości: publiczne API pokazuje artykuł dopiero od tej chwili.
 * body         — Markdown. HTML powstaje przy odczycie (ArticleMarkdown) z usuniętym
 *                surowym HTML-em; w bazie nie trzymamy gotowego HTML-a, bo zmiana
 *                reguł sanityzacji musiałaby przepisać wszystkie wiersze.
 * slug         — nadawany przy utworzeniu i NIEZMIENNY: poprawka tytułu nie psuje
 *                linków udostępnionych w mediach społecznościowych.
 * movie_id     — film, którego dotyczy artykuł; wymagany dla premiery. RESTRICT jak
 *                screenings -> movies: filmów się nie usuwa (decyzja z bloku F).
 * author_id    — kto utworzył; SET NULL, bo artykuł przetrwa usunięcie konta.
 *
 * CHECK-i pilnują reguł także poza panelem (tinker, seeder, przyszłe API admina).
 * Indeks częściowy obejmuje tylko opublikowane — to jedyne, które czyta API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20);
            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->string('excerpt', 300);
            $table->text('body');
            $table->string('status', 20)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('movie_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_type_check CHECK (type IN ('news', 'premiere'))");
        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_status_check CHECK (status IN ('draft', 'published'))");
        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_published_has_date CHECK (status <> 'published' OR published_at IS NOT NULL)");
        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_premiere_has_movie CHECK (type <> 'premiere' OR movie_id IS NOT NULL)");
        DB::statement("CREATE INDEX articles_published ON articles (published_at DESC, id DESC) WHERE status = 'published'");
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};

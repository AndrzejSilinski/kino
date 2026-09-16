<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ScreeningStatus;
use App\Exceptions\StructureChangeBlockedException;
use App\Livewire\Admin\Movies\MovieForm;
use App\Livewire\Admin\Movies\MovieIndex;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\User;
use App\Services\Admin\MovieAdminService;
use App\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filmy i plakaty w panelu (Etap 7, blok F): dostęp, formularz, plik i baza
 * w zgodzie przy COMMIT i ROLLBACK, blokady przy nadchodzących seansach.
 *
 * Storage::fake('public') podmienia dysk plakatów na katalog tymczasowy;
 * Livewire::test sam przechowuje wgrywane pliki na fałszywym dysku tymczasowym.
 */
final class MovieManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** @return array{title: string, original_title: ?string, description: string, duration_minutes: int, age_rating: string, genres: list<string>, premiere_date: ?string} */
    private function data(array $override = []): array
    {
        return [
            'title' => 'Film testowy',
            'original_title' => null,
            'description' => 'Opis filmu testowego.',
            'duration_minutes' => 120,
            'age_rating' => '12',
            'genres' => ['Dramat'],
            'premiere_date' => null,
            ...$override,
        ];
    }

    /** @var list<UploadedFile> */
    private array $uploads = [];

    /**
     * Prawdziwy plik JPEG na dysku (UploadedFile::fake rysuje go przez GD).
     * Obiekt trzymamy w teście: to plik tmpfile(), który znika razem z obiektem.
     */
    private function imagePath(int $width = 600, int $height = 900): string
    {
        $this->uploads[] = $file = UploadedFile::fake()->image('plakat.jpg', $width, $height);

        return $file->getRealPath();
    }

    /** @return list<string> */
    private function posterFiles(): array
    {
        return Storage::disk('public')->files(MovieAdminService::POSTER_DIRECTORY);
    }

    // ─── Dostęp ──────────────────────────────────────────────────────────

    public function test_movie_pages_are_for_admin_only(): void
    {
        $movie = Movie::factory()->create();
        $urls = [route('admin.movies.index'), route('admin.movies.create'), route('admin.movies.edit', $movie)];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }

        $staff = User::factory()->staff()->create();
        foreach ($urls as $url) {
            $this->actingAs($staff, 'web')->get($url)->assertForbidden();
        }
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.movies.index'));
        Livewire::actingAs($staff)->test(MovieForm::class)->assertForbidden();
        Livewire::actingAs($staff)->test(MovieIndex::class)->assertForbidden();

        $this->app['auth']->forgetGuards();

        $admin = $this->admin();
        foreach ($urls as $url) {
            $this->actingAs($admin, 'web')->get($url)->assertOk();
        }
        $this->get(route('admin.dashboard'))->assertSee(route('admin.movies.index'));
    }

    // ─── Formularz ───────────────────────────────────────────────────────

    public function test_admin_adds_movie_with_poster_saved_as_scaled_jpeg(): void
    {
        Livewire::actingAs($this->admin())
            ->test(MovieForm::class)
            ->set('title', 'Diuna: Część trzecia')
            ->set('originalTitle', 'Dune: Part Three')
            ->set('description', 'Opis.')
            ->set('durationMinutes', '155')
            ->set('ageRating', '12')
            ->set('genres', ' Sci-Fi, Przygodowy , sci-fi, ')
            ->set('premiereDate', '2026-12-18')
            ->set('poster', UploadedFile::fake()->image('plakat.png', 1000, 1500))
            ->assertHasNoErrors()
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.movies.index'));

        $movie = Movie::query()->where('slug', 'diuna-czesc-trzecia')->firstOrFail();
        $this->assertSame(['Sci-Fi', 'Przygodowy'], $movie->genres);
        $this->assertSame(155, $movie->duration_minutes);
        $this->assertSame('2026-12-18', $movie->premiere_date->toDateString());
        $this->assertMatchesRegularExpression('#^posters/[0-9a-z]{26}\.jpg$#', (string) $movie->poster_path);

        $info = getimagesizefromstring(Storage::disk('public')->get($movie->poster_path));
        $this->assertSame([800, 1200, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]], 'PNG 1000×1500 -> JPEG 800×1200.');
        $this->assertSame([$movie->poster_path], $this->posterFiles());
    }

    public function test_form_validation_messages(): void
    {
        $component = Livewire::actingAs($this->admin())->test(MovieForm::class)
            ->set('durationMinutes', '0')
            ->set('ageRating', '13+')
            ->set('premiereDate', '18.12.2026')
            ->call('save')
            ->assertHasErrors(['title', 'description', 'genres', 'durationMinutes', 'ageRating', 'premiereDate'])
            ->assertSee('Wybierz kategorię wiekową z listy.')
            ->assertSee('Data premiery musi mieć format RRRR-MM-DD.');

        $component
            ->set('title', 'Film')->set('description', 'Opis')->set('durationMinutes', '90')
            ->set('ageRating', '7')->set('premiereDate', '')
            ->set('genres', 'a, b, c, d, e, f')
            ->call('save')
            ->assertHasErrors(['genres'])
            ->assertSee('Podaj od 1 do 5 gatunków');

        $this->assertSame(0, Movie::query()->count());
    }

    public function test_poster_is_checked_right_after_upload(): void
    {
        $component = Livewire::actingAs($this->admin())->test(MovieForm::class);

        $component->set('poster', UploadedFile::fake()->image('maly.jpg', 200, 300))
            ->assertHasErrors(['poster'])
            ->assertSee('Plakat musi mieć co najmniej 300 × 450 px.');

        $component->set('poster', UploadedFile::fake()->create('plakat.pdf', 100, 'application/pdf'))
            ->assertHasErrors(['poster']);

        $component->set('poster', UploadedFile::fake()->image('duzy.jpg', 600, 900)->size(6000))
            ->assertHasErrors(['poster']);

        $component->set('poster', UploadedFile::fake()->image('dobry.jpg', 600, 900))
            ->assertHasNoErrors();
    }

    /**
     * Endpoint wgrywania Livewire (config livewire.temporary_file_upload.rules) odrzuca
     * plik, zanim trafi do katalogu tymczasowego — niezależnie od formularza.
     */
    public function test_temporary_upload_endpoint_applies_poster_rules(): void
    {
        // W testach Livewire trzyma pliki tymczasowe na dysku "tmp-for-tests" (Livewire::test
        // tworzy go sam; tu wołamy endpoint bez komponentu, więc podmieniamy dysk ręcznie).
        Storage::fake('tmp-for-tests');
        // Livewire sprawdza podpis WZGLĘDNY (hasValidRelativeSignature) — ostatni argument false.
        $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), [], false);
        // Przeglądarka Livewire wysyła ten plik przez XHR i czeka na JSON (422 z błędami, nie 302).
        $this->actingAs($this->admin(), 'web')->withHeaders(['Accept' => 'application/json']);

        $this->post($url, ['files' => [UploadedFile::fake()->image('duzy.jpg', 600, 900)->size(6000)]])
            ->assertStatus(422);
        $this->post($url, ['files' => [UploadedFile::fake()->create('skrypt.php', 1, 'text/x-php')]])
            ->assertStatus(422);
        $this->post($url, ['files' => [UploadedFile::fake()->image('dobry.jpg', 600, 900)]])
            ->assertOk()
            ->assertJsonCount(1, 'paths');
    }

    public function test_decompression_bomb_is_reported_on_poster_field_and_nothing_is_saved(): void
    {
        $ihdr = pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));

        Livewire::actingAs($this->admin())->test(MovieForm::class)
            ->set('title', 'Film')->set('description', 'Opis')->set('durationMinutes', '90')
            ->set('ageRating', '7')->set('genres', 'Dramat')
            ->set('poster', UploadedFile::fake()->createWithContent('bomba.png', $png))
            ->call('save')
            ->assertHasErrors(['poster'])
            ->assertSee('to więcej niż 16 Mpx');

        $this->assertSame(0, Movie::query()->count());
        $this->assertSame([], $this->posterFiles());
    }

    public function test_slug_is_unique_and_does_not_change_with_title(): void
    {
        $service = app(MovieAdminService::class);
        $first = $service->create($this->data(['title' => 'Incepcja']));
        $second = $service->create($this->data(['title' => 'Incepcja']));

        $this->assertSame(['incepcja', 'incepcja-2'], [$first->slug, $second->slug]);

        $renamed = $service->update($second, $this->data(['title' => 'Incepcja (wersja reżyserska)']));
        $this->assertSame('incepcja-2', $renamed->slug);
    }

    public function test_locked_movie_id_cannot_be_changed_from_the_browser(): void
    {
        $movie = Movie::factory()->create();
        $other = Movie::factory()->create();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin())
            ->test(MovieForm::class, ['movie' => $movie])
            ->set('movieId', $other->id);
    }

    // ─── Plik i baza ─────────────────────────────────────────────────────

    public function test_new_poster_replaces_old_file_after_commit_and_invalidates_cache(): void
    {
        $service = app(MovieAdminService::class);
        $movie = $service->create($this->data(), $this->imagePath());
        $old = $movie->poster_path;
        $generation = app(CatalogCache::class)->generation(CatalogCache::MOVIES);

        $updated = $service->update($movie, $this->data(['title' => 'Nowy tytuł']), $this->imagePath(700, 1050));

        $this->assertNotSame($old, $updated->poster_path);
        $this->assertSame([$updated->poster_path], $this->posterFiles(), 'Stary plik usunięty po COMMIT.');
        $this->assertSame($generation + 1, app(CatalogCache::class)->generation(CatalogCache::MOVIES));
    }

    public function test_blocked_update_keeps_old_poster_and_removes_the_new_file(): void
    {
        $service = app(MovieAdminService::class);
        $movie = $service->create($this->data(), $this->imagePath());
        $old = $movie->poster_path;
        Screening::factory()->for($movie)->create();
        $generation = app(CatalogCache::class)->generation(CatalogCache::MOVIES);

        try {
            $service->update($movie, $this->data(['duration_minutes' => 150]), $this->imagePath(700, 1050));
            $this->fail('Oczekiwano MOVIE_DURATION_LOCKED.');
        } catch (StructureChangeBlockedException $e) {
            $this->assertSame('MOVIE_DURATION_LOCKED', $e->errorCode());
        }

        $this->assertSame($old, $movie->fresh()->poster_path);
        $this->assertSame([$old], $this->posterFiles(), 'Nowy plik usunięty po ROLLBACK, stary nietknięty.');
        $this->assertSame($generation, app(CatalogCache::class)->generation(CatalogCache::MOVIES));
    }

    public function test_failure_after_saving_row_rolls_back_and_keeps_old_file(): void
    {
        $service = app(MovieAdminService::class);
        $movie = $service->create($this->data(), $this->imagePath());
        $old = $movie->poster_path;

        // Awaria PO zapisie wiersza, jeszcze przed COMMIT (np. zerwane połączenie).
        Movie::saved(function (): void {
            throw new \RuntimeException('awaria po zapisie');
        });

        try {
            $service->update($movie, $this->data(['title' => 'Nie zapisze się']), $this->imagePath(700, 1050));
            $this->fail('Oczekiwano wyjątku.');
        } catch (\RuntimeException $e) {
            $this->assertSame('awaria po zapisie', $e->getMessage());
        } finally {
            Movie::flushEventListeners();
        }

        $this->assertSame($old, $movie->fresh()->poster_path);
        $this->assertSame([$old], $this->posterFiles(), 'Stary plik usuwany dopiero po COMMIT, nowy sprzątnięty.');
    }

    public function test_only_files_from_posters_directory_are_ever_deleted(): void
    {
        Storage::disk('public')->put('inne/wazny.txt', 'nie usuwać');
        $movie = Movie::factory()->create(['poster_path' => 'inne/wazny.txt', 'age_rating' => '12']);

        app(MovieAdminService::class)->update($movie, $this->data(), $this->imagePath());

        Storage::disk('public')->assertExists('inne/wazny.txt');
    }

    public function test_save_checks_permission_on_every_call(): void
    {
        $movie = Movie::factory()->create(['title' => 'Bez zmian', 'age_rating' => '12']);
        $staff = User::factory()->staff()->create();

        // Formularz otwarty przez admina, zapis przychodzi w sesji bez uprawnień.
        $component = Livewire::actingAs($this->admin())->test(MovieForm::class, ['movie' => $movie]);
        $this->actingAs($staff);
        $component->set('title', 'Zmiana')->call('save')->assertForbidden();

        $component = Livewire::actingAs($this->admin())->test(MovieIndex::class);
        $this->actingAs($staff);
        $component->call('toggleActive', $movie->id)->assertForbidden();

        $this->assertSame('Bez zmian', $movie->fresh()->title);
        $this->assertTrue($movie->fresh()->is_active);
    }

    public function test_duration_change_error_is_shown_in_form_and_other_fields_can_change(): void
    {
        $movie = Movie::factory()->create(['age_rating' => '12', 'duration_minutes' => 120]);
        Screening::factory()->for($movie)->create();

        $component = Livewire::actingAs($this->admin())->test(MovieForm::class, ['movie' => $movie])
            ->set('durationMinutes', '130')
            ->call('save')
            ->assertHasErrors(['durationMinutes'])
            ->assertSee('Nie można zmienić czasu trwania filmu');

        $component->set('durationMinutes', '120')->set('title', 'Nowy tytuł')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.movies.index'));

        $this->assertSame('Nowy tytuł', $movie->fresh()->title);
    }

    public function test_poster_can_be_removed(): void
    {
        $service = app(MovieAdminService::class);
        $movie = $service->create($this->data(), $this->imagePath());

        Livewire::actingAs($this->admin())->test(MovieForm::class, ['movie' => $movie])
            ->set('removePoster', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($movie->fresh()->poster_path);
        $this->assertSame([], $this->posterFiles());
    }

    // ─── Włączanie i wyłączanie ──────────────────────────────────────────

    public function test_deactivation_is_blocked_by_upcoming_screening_only(): void
    {
        $movie = Movie::factory()->create();
        $screening = Screening::factory()->for($movie)->create();
        Screening::factory()->for($movie)->finished()->create();

        Livewire::actingAs($this->admin())->test(MovieIndex::class)
            ->assertSee($movie->title)
            ->call('toggleActive', $movie->id)
            ->assertSet('problem', 'Nie można wyłączyć filmu, który ma nadchodzące seanse (1). Najpierw je odwołaj.');
        $this->assertTrue($movie->fresh()->is_active);

        $screening->update(['status' => ScreeningStatus::Cancelled]);

        Livewire::actingAs($this->admin())->test(MovieIndex::class)
            ->call('toggleActive', $movie->id)
            ->assertSet('notice', "Wyłączono film {$movie->title}.");
        $this->assertFalse($movie->fresh()->is_active);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Exceptions\InvalidArticleException;
use App\Livewire\Admin\Articles\ArticleForm;
use App\Livewire\Admin\Articles\ArticleIndex;
use App\Models\Article;
use App\Models\Cinema;
use App\Models\Movie;
use App\Models\User;
use App\Services\Admin\ArticleAdminService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Artykuły w panelu (Etap 7, blok M): tylko administrator, walidacja premiery,
 * data publikacji w strefie sieci, stały slug, podgląd bez HTML-a, usuwanie.
 */
final class ArticleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'UTC'));
        $this->admin = User::factory()->admin()->create(['name' => 'Redaktor T']);
    }

    public function test_only_admin_reaches_article_pages_and_actions(): void
    {
        $article = Article::factory()->create();
        $staff = User::factory()->staff(Cinema::factory()->create())->create();

        $this->actingAs($staff, 'web');
        $this->get(route('admin.articles.index'))->assertForbidden();
        $this->get(route('admin.articles.create'))->assertForbidden();
        $this->get(route('admin.articles.edit', $article))->assertForbidden();
        $this->get(route('admin.dashboard'))->assertDontSee(route('admin.articles.index'));

        $index = Livewire::actingAs($this->admin)->test(ArticleIndex::class);
        $this->actingAs($staff);
        $index->call('deleteArticle', $article->id)->assertForbidden();
        $this->assertModelExists($article);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'web')->get(route('admin.dashboard'))->assertSee(route('admin.articles.index'));
        $this->get(route('admin.articles.index'))->assertOk()->assertSee($article->title);
    }

    public function test_admin_creates_scheduled_premiere_with_local_time_and_slug(): void
    {
        $movie = Movie::factory()->create(['title' => 'Diuna T']);

        Livewire::actingAs($this->admin)->test(ArticleForm::class)
            ->set('type', 'premiere')
            ->set('title', 'Zażółć gęślą: premiera!')
            ->set('excerpt', 'Krótko.')
            ->set('body', 'Treść <script>alert(1)</script> **ważna**')
            ->assertSeeHtml('<strong>ważna</strong>')
            ->assertDontSeeHtml('<script>alert(1)')
            ->set('status', 'published')
            ->set('publishDate', '2026-10-24')
            ->call('save')
            ->assertHasErrors(['movieId' => 'required_if', 'publishTime' => 'required_with'])
            ->set('movieId', (string) $movie->id)
            ->set('publishTime', '18:30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.articles.index'));

        $article = Article::query()->sole();
        $this->assertSame('zazolc-gesla-premiera', $article->slug);
        $this->assertSame(ArticleType::Premiere, $article->type);
        $this->assertSame(ArticleStatus::Published, $article->status);
        // 24.10 w Warszawie to jeszcze czas letni (UTC+2).
        $this->assertSame('2026-10-24 16:30:00', $article->published_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($this->admin->id, $article->author_id);

        $this->get(route('admin.articles.index'))->assertOk();
        Livewire::actingAs($this->admin)->test(ArticleIndex::class)
            ->assertSee('zaplanowany')->assertSee('2026-10-24 18:30')->assertSee('Redaktor T');
    }

    public function test_edit_keeps_slug_rejects_nonexistent_local_time_and_second_article_gets_suffix(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ArticleForm::class)
            ->set('title', 'Nowe fotele')->set('excerpt', 'Zajawka.')->set('body', 'Treść.')
            ->set('status', 'published')
            ->call('save')->assertHasNoErrors();
        $first = Article::query()->sole();
        $this->assertSame('2026-10-01 10:00:00', $first->published_at->utc()->format('Y-m-d H:i:s'), 'Opublikowany bez daty = teraz.');

        Livewire::actingAs($this->admin)->test(ArticleForm::class)
            ->set('title', 'Nowe fotele')->set('excerpt', 'Zajawka.')->set('body', 'Treść.')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(['nowe-fotele', 'nowe-fotele-2'], Article::query()->orderBy('id')->pluck('slug')->all());

        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00', 'UTC'));
        Livewire::actingAs($this->admin)->test(ArticleForm::class, ['article' => $first])
            ->assertSet('publishDate', '2026-10-01')->assertSet('publishTime', '12:00')
            ->set('title', 'Fotele w sali IMAX')
            // 28.03.2027 02:30 nie istnieje w Warszawie (zegar przeskakuje z 2:00 na 3:00).
            ->set('publishDate', '2027-03-28')->set('publishTime', '02:30')
            ->call('save')
            ->assertHasErrors('publishDate')
            ->set('publishDate', '')->set('publishTime', '')
            ->call('save')->assertHasNoErrors();

        $first->refresh();
        $this->assertSame('nowe-fotele', $first->slug);
        $this->assertSame('Fotele w sali IMAX', $first->title);
        $this->assertSame('2026-10-01 10:00:00', $first->published_at->utc()->format('Y-m-d H:i:s'), 'Edycja bez daty zachowuje moment publikacji.');
    }

    public function test_service_and_database_enforce_rules_outside_the_form(): void
    {
        $service = app(ArticleAdminService::class);
        $data = ['type' => ArticleType::Premiere, 'title' => 'Premiera bez filmu', 'excerpt' => 'Z.', 'body' => 'T.', 'movie_id' => null, 'status' => ArticleStatus::Draft, 'published_at' => null];

        foreach ([$data, [...$data, 'type' => ArticleType::News, 'movie_id' => 999999]] as $invalid) {
            try {
                $service->create($invalid, $this->admin);
                $this->fail('Oczekiwano ARTICLE_INVALID');
            } catch (InvalidArticleException $e) {
                $this->assertSame('ARTICLE_INVALID', $e->errorCode());
                $this->assertSame(422, $e->status());
                $this->assertSame('movieId', $e->field());
            }
        }
        $this->assertSame(0, Article::query()->count());

        // CHECK-i w bazie: premiera bez filmu i opublikowany bez daty (np. z tinkera).
        foreach ([['type' => 'premiere', 'status' => 'draft', 'published_at' => null], ['type' => 'news', 'status' => 'published', 'published_at' => null]] as $i => $row) {
            try {
                DB::transaction(fn () => DB::table('articles')->insert([...$row, 'title' => 'X', 'slug' => 'x-'.$i, 'excerpt' => 'X', 'body' => 'X', 'created_at' => now(), 'updated_at' => now()]));
                $this->fail('Oczekiwano naruszenia CHECK');
            } catch (QueryException $e) {
                $this->assertStringContainsString($i === 0 ? 'articles_premiere_has_movie' : 'articles_published_has_date', $e->getMessage());
            }
        }
    }

    public function test_filters_visibility_labels_and_delete(): void
    {
        $visible = Article::factory()->published('2026-09-30 10:00')->create(['title' => 'Widoczny T']);
        Article::factory()->published('2026-10-05 10:00')->create(['title' => 'Zaplanowany T']);
        Article::factory()->premiere()->create(['title' => 'Szkic premiery T']);

        $index = Livewire::actingAs($this->admin)->test(ArticleIndex::class)
            ->assertSee('Widoczny T')->assertSee('Zaplanowany T')->assertSee('Szkic premiery T')
            ->set('type', 'premiere')->assertSee('Szkic premiery T')->assertDontSee('Widoczny T')
            ->set('type', '')->set('status', 'published')->assertDontSee('Szkic premiery T')
            ->set('status', '')->set('search', 'zaplan')->assertSee('Zaplanowany T')->assertDontSee('Widoczny T')
            ->set('search', '%')->assertDontSee('Widoczny T');

        $index->set('search', '')->call('deleteArticle', $visible->id)->assertSee('Usunięto artykuł')->assertDontSee('Widoczny T</td>', false);
        $this->assertModelMissing($visible);
    }
}

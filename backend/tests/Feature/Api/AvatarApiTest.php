<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST/DELETE /account/avatar (Etap 8, blok I).
 *
 * Obrazy tworzone przez GD w teście (UploadedFile::fake()->image), dysk public podmieniony
 * przez Storage::fake — sprawdzamy prawdziwy wynik przekodowania, a nie atrapę procesora.
 */
final class AvatarApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        $this->user = User::factory()->create()->fresh();
        Sanctum::actingAs($this->user);
    }

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->post('/api/v1/account/avatar', ['avatar' => $file], ['Accept' => 'application/json']);
    }

    public function test_avatar_jest_kwadratowym_jpeg_256_pod_losowa_nazwa_z_publicznym_adresem(): void
    {
        $response = $this->upload(UploadedFile::fake()->image('wakacje.png', 800, 600))->assertOk();

        $path = (string) $this->user->refresh()->avatar_path;
        $this->assertMatchesRegularExpression('#\Aavatars/[0-9a-f]{40}\.jpg\z#', $path, 'Nazwa losowa, bez nazwy wgranego pliku i bez znacznika czasu.');
        Storage::disk('public')->assertExists($path);
        $this->assertSame(Storage::disk('public')->url($path), $response->json('data.avatar_url'));

        $info = getimagesizefromstring((string) Storage::disk('public')->get($path));
        $this->assertSame([256, 256, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
    }

    public function test_zmiana_avatara_usuwa_stary_plik_po_zapisie(): void
    {
        $this->upload(UploadedFile::fake()->image('a.jpg', 300, 300))->assertOk();
        $first = (string) $this->user->refresh()->avatar_path;

        $this->upload(UploadedFile::fake()->image('b.jpg', 300, 300))->assertOk();
        $second = (string) $this->user->refresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_usuniecie_avatara_jest_idempotentne(): void
    {
        $this->upload(UploadedFile::fake()->image('a.jpg', 300, 300))->assertOk();
        $path = (string) $this->user->refresh()->avatar_path;

        $this->deleteJson('/api/v1/account/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->deleteJson('/api/v1/account/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($this->user->refresh()->avatar_path);
    }

    public function test_za_maly_obraz_odrzuca_procesor_z_kodem_avatar_invalid(): void
    {
        $this->upload(UploadedFile::fake()->image('ikona.png', 64, 64))
            ->assertStatus(422)
            ->assertJsonPath('code', 'AVATAR_INVALID')
            ->assertJsonPath('context.reason', 'too_small');

        $this->assertNull($this->user->refresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_plik_udajacy_obraz_rozszerzeniem_zatrzymuje_procesor(): void
    {
        // Rozszerzenie .png, treść to skrypt. Nawet gdy walidator mimes da się oszukać, procesor
        // czyta nagłówek pliku i niczego nie zapisuje — na dysk trafiają wyłącznie piksele z GD.
        $this->upload(UploadedFile::fake()->createWithContent('avatar.png', '<?php echo "to nie obraz";'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'AVATAR_INVALID')
            ->assertJsonPath('context.reason', 'unreadable');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_plik_innego_typu_odrzuca_walidator(): void
    {
        $this->upload(UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.avatar.0', 'Avatar musi być plikiem JPG albo PNG.');
    }

    public function test_za_duzy_plik_odrzuca_walidator_z_limitem_w_komunikacie(): void
    {
        $this->upload(UploadedFile::fake()->create('duzy.jpg', 6000, 'image/jpeg'))
            ->assertStatus(422)
            ->assertJsonPath('errors.avatar.0', 'Avatar nie może być większy niż 5 MB.');
    }

    public function test_bomba_pikselowa_odrzucona_przed_dekodowaniem(): void
    {
        // Nagłówek PNG deklarujący 20 000 × 20 000 px w pliku kilkudziesięciu bajtów.
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);
        $png .= pack('N', crc32(substr($png, 12, 17))).pack('N', 0).'IEND'.pack('N', crc32('IEND'));

        $this->upload(UploadedFile::fake()->createWithContent('bomba.png', $png))
            ->assertStatus(422)
            ->assertJsonPath('code', 'AVATAR_INVALID')
            ->assertJsonPath('context.reason', 'too_many_pixels');
    }
}

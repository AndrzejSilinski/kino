<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Enums\BookingStatus;
use App\Events\BookingPaid;
use App\Listeners\SendPaymentPush;
use App\Models\Booking;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\PushDevice;
use App\Models\Screening;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\PaymentConfirmedPush;
use App\Notifications\ScreeningReminder;
use App\Push\PushResult;
use App\Push\PushSender;
use App\Push\PushTemporarilyUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Fakes\FakePushSender;
use Tests\TestCase;

/**
 * Push po płatności i przy przypomnieniu (Etap 8, blok K, wymóg 3.5) oraz kanał push.
 * Wysyłka przez FakePushSender — w PHPUnit nie ma żadnego połączenia z Google.
 */
final class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private FakePushSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        config(['push.enabled' => true]);
        $this->sender = new FakePushSender;
        $this->app->instance(PushSender::class, $this->sender);
    }

    private function user(bool $consent = true, int $devices = 1): User
    {
        $user = User::factory()->create(['name' => 'Anna Tajemnicza', 'email' => 'anna.tajemnicza.'.Str::lower(Str::random(8)).'@example.com']);
        $user->forceFill(['push_consent_at' => $consent ? CarbonImmutable::now() : null])->save();
        for ($i = 0; $i < $devices; $i++) {
            (new PushDevice)->forceFill(['user_id' => $user->id, 'token' => 'token-'.$i.'-'.Str::random(40), 'platform' => 'web', 'last_seen_at' => CarbonImmutable::now()])->save();
        }

        return $user->refresh();
    }

    private function paidBooking(User $user): Booking
    {
        $hall = Hall::factory()->withSeats(1, 1)->create();
        $startsAt = CarbonImmutable::parse('2026-09-20 16:30:00', 'UTC');
        $screening = Screening::factory()->for($hall)->for(Movie::factory()->create(['title' => 'Barbie']))->create([
            'starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes(114), 'slot_ends_at' => $startsAt->addMinutes(134),
        ]);

        return Booking::factory()->paid()->create(['user_id' => $user->id, 'screening_id' => $screening->id]);
    }

    public function test_platnosc_wysyla_push_z_filmem_i_godzina_w_strefie_kina_bez_danych_osobowych(): void
    {
        $user = $this->user(devices: 2);
        $booking = $this->paidBooking($user);

        event(new BookingPaid($booking->id));

        $this->assertCount(2, $this->sender->sent);
        $message = $this->sender->sent[0]['message'];
        $timezoneTime = $booking->screening->starts_at->copy()->setTimezone($booking->screening->hall->cinema->timezone)->format('H:i');
        $this->assertSame('Płatność przyjęta', $message->title);
        $this->assertSame('Barbie · 20.09, godz. '.$timezoneTime, $message->body);
        $this->assertSame(['booking.paid', '/bookings/'.$booking->reference], [$message->type, $message->url]);
        foreach (['Anna', 'Tajemnicza', 'anna.tajemnicza', $booking->reference] as $personal) {
            $this->assertStringNotContainsString($personal, $message->title.' '.$message->body);
        }
    }

    public function test_push_po_platnosci_najwyzej_raz_takze_przy_powtorzonym_zdarzeniu(): void
    {
        $booking = $this->paidBooking($this->user());

        event(new BookingPaid($booking->id));
        event(new BookingPaid($booking->id));

        $this->assertCount(1, $this->sender->sent);
        $this->assertNotNull($booking->refresh()->payment_push_sent_at);
    }

    public function test_bez_zgody_albo_przy_wylaczonym_push_nic_nie_wychodzi(): void
    {
        event(new BookingPaid($this->paidBooking($this->user(consent: false))->id));
        $this->assertSame([], $this->sender->sent);

        config(['push.enabled' => false]);
        $booking = $this->paidBooking($this->user());
        event(new BookingPaid($booking->id));
        $this->assertSame([], $this->sender->sent);
        $this->assertNull($booking->refresh()->payment_push_sent_at, 'Wyłączony push nie zajmuje rezerwacji.');
    }

    public function test_przypomnienie_idzie_mailem_i_pushem_tylko_przy_zgodzie(): void
    {
        $withConsent = $this->user();
        $withoutConsent = $this->user(consent: false);

        $this->assertSame(['mail', PushChannel::class], (new ScreeningReminder(1))->via($withConsent));
        $this->assertSame(['mail'], (new ScreeningReminder(1))->via($withoutConsent));

        $booking = $this->paidBooking($withConsent);
        $message = (new ScreeningReminder($booking->id))->toPush($withConsent);
        $this->assertSame(['Przypomnienie o seansie', 'screening.reminder'], [$message->title, $message->type]);
    }

    public function test_kanal_usuwa_urzadzenia_z_niewaznym_tokenem(): void
    {
        $user = $this->user(devices: 2);
        [$dead, $alive] = $user->pushDevices()->orderBy('id')->get()->all();
        $this->sender->respond($dead->token, PushResult::invalidToken('UNREGISTERED'));

        $user->notifyNow(new PaymentConfirmedPush($this->paidBooking($user)->id));

        $this->assertSame([$alive->id], PushDevice::query()->pluck('id')->all());
    }

    public function test_kanal_ponawia_tylko_gdy_nikt_nie_dostal_powiadomienia(): void
    {
        $user = $this->user(devices: 2);
        [$first, $second] = $user->pushDevices()->orderBy('id')->get()->all();
        $booking = $this->paidBooking($user);

        $this->sender->respond($first->token, PushResult::retryable('UNAVAILABLE'));
        $user->notifyNow(new PaymentConfirmedPush($booking->id));
        $this->assertCount(2, $this->sender->sent, 'Częściowy sukces: bez wyjątku i bez ponowienia (duplikat na drugim urządzeniu).');

        $this->sender->respond($second->token, PushResult::retryable('UNAVAILABLE'));
        $this->expectException(PushTemporarilyUnavailableException::class);
        $user->notifyNow(new PaymentConfirmedPush($booking->id));
    }

    public function test_nieoplacona_rezerwacja_nie_dostaje_pushu_o_platnosci(): void
    {
        $user = $this->user();
        $booking = $this->paidBooking($user);
        $booking->forceFill(['status' => BookingStatus::Cancelled])->save();

        Notification::fake();
        (new SendPaymentPush)->handle(new BookingPaid($booking->id));

        Notification::assertNothingSent();
    }
}

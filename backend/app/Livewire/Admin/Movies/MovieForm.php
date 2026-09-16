<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Movies;

use App\Exceptions\InvalidPosterException;
use App\Exceptions\StructureChangeBlockedException;
use App\Models\Movie;
use App\Services\Admin\MovieAdminService;
use App\Services\Admin\PosterImageProcessor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Dodawanie i edycja filmu z plakatem (Etap 7, blok F).
 *
 * WGRYWANIE W LIVEWIRE: przeglądarka wysyła plik od razu po wybraniu, osobnym
 * żądaniem pod podpisany adres; plik ląduje w storage/app/private/livewire-tmp
 * (reguły z config/livewire.php), a właściwość $poster dostaje obiekt
 * TemporaryUploadedFile. Dopiero save() przekazuje ścieżkę do serwisu, który
 * przekodowuje obraz i zapisuje go na dysku public.
 *
 * Pola liczbowe i daty trzymamy jako tekst: pusty input to '' i dopiero
 * walidacja mówi, czy to poprawna liczba — bez cichego rzutowania na 0.
 */
final class MovieForm extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $movieId = null;

    public string $title = '';

    public string $originalTitle = '';

    public string $description = '';

    public string $durationMinutes = '';

    public string $ageRating = '';

    /** Gatunki po przecinku, np. "Sci-Fi, Thriller". */
    public string $genres = '';

    public string $premiereDate = '';

    /** @var TemporaryUploadedFile|null */
    public $poster = null;

    public bool $removePoster = false;

    public function mount(?Movie $movie = null): void
    {
        if ($movie === null || ! $movie->exists) {
            $this->authorize('create', Movie::class);

            return;
        }

        $this->authorize('update', $movie);

        $this->movieId = $movie->id;
        $this->title = $movie->title;
        $this->originalTitle = (string) $movie->original_title;
        $this->description = $movie->description;
        $this->durationMinutes = (string) $movie->duration_minutes;
        $this->ageRating = $movie->age_rating;
        $this->genres = implode(', ', $movie->genres ?? []);
        $this->premiereDate = (string) $movie->premiere_date?->toDateString();
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'originalTitle' => ['nullable', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
            'durationMinutes' => ['required', 'integer', 'min:1', 'max:600'],
            'ageRating' => ['required', Rule::in(MovieAdminService::AGE_RATINGS)],
            'genres' => ['required', 'string', 'max:200'],
            'premiereDate' => ['nullable', 'date_format:Y-m-d'],
            'poster' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png',
                'max:5120',
                'dimensions:min_width='.PosterImageProcessor::MIN_WIDTH.',min_height='.PosterImageProcessor::MIN_HEIGHT,
            ],
            'removePoster' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'poster.max' => 'Plakat może mieć najwyżej 5 MB.',
            'poster.mimes' => 'Plakat musi być plikiem JPG albo PNG.',
            'poster.dimensions' => 'Plakat musi mieć co najmniej '.PosterImageProcessor::MIN_WIDTH.' × '.PosterImageProcessor::MIN_HEIGHT.' px.',
            'ageRating.in' => 'Wybierz kategorię wiekową z listy.',
            'premiereDate.date_format' => 'Data premiery musi mieć format RRRR-MM-DD.',
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'title' => 'tytuł',
            'originalTitle' => 'tytuł oryginalny',
            'description' => 'opis',
            'durationMinutes' => 'czas trwania',
            'ageRating' => 'kategoria wiekowa',
            'genres' => 'gatunki',
            'premiereDate' => 'data premiery',
        ];
    }

    /** Walidacja pliku zaraz po wgraniu — błąd widać przed kliknięciem "Zapisz". */
    public function updatedPoster(): void
    {
        $this->validateOnly('poster');
        $this->removePoster = false;
    }

    public function save(): void
    {
        $this->validate();

        $genres = $this->parsedGenres();

        if ($genres === [] || count($genres) > 5 || max(array_map('mb_strlen', $genres)) > 30) {
            $this->addError('genres', 'Podaj od 1 do 5 gatunków oddzielonych przecinkami, każdy do 30 znaków.');

            return;
        }

        $data = [
            'title' => trim($this->title),
            'original_title' => trim($this->originalTitle) === '' ? null : trim($this->originalTitle),
            'description' => trim($this->description),
            'duration_minutes' => (int) $this->durationMinutes,
            'age_rating' => $this->ageRating,
            'genres' => $genres,
            'premiere_date' => $this->premiereDate === '' ? null : $this->premiereDate,
        ];
        $source = $this->poster instanceof TemporaryUploadedFile ? $this->poster->getRealPath() : null;
        $service = app(MovieAdminService::class);

        try {
            if ($this->movieId === null) {
                $this->authorize('create', Movie::class);
                $movie = $service->create($data, $source ?: null);
                session()->flash('status', "Dodano film {$movie->title} (adres: {$movie->slug}).");
            } else {
                $movie = Movie::query()->findOrFail($this->movieId);
                $this->authorize('update', $movie);
                $movie = $service->update($movie, $data, $source ?: null, $this->removePoster);
                session()->flash('status', "Zapisano zmiany w filmie {$movie->title}.");
            }
        } catch (InvalidPosterException $e) {
            $this->addError('poster', $e->getMessage());

            return;
        } catch (StructureChangeBlockedException $e) {
            $this->addError('durationMinutes', $e->getMessage());

            return;
        }

        // Plik tymczasowy nie jest już potrzebny — plakat jest przekodowany na dysku public.
        if ($this->poster instanceof TemporaryUploadedFile) {
            $this->poster->delete();
        }

        $this->redirectRoute('admin.movies.index');
    }

    public function render(): View
    {
        $currentPoster = $this->movieId === null
            ? null
            : Movie::query()->whereKey($this->movieId)->value('poster_path');

        return view('livewire.admin.movies.form', [
            'editing' => $this->movieId !== null,
            'ageRatings' => MovieAdminService::AGE_RATINGS,
            'currentPosterUrl' => $currentPoster === null ? null : Storage::disk('public')->url($currentPoster),
            'previewable' => $this->poster instanceof TemporaryUploadedFile
                && ! $this->getErrorBag()->has('poster')
                && $this->poster->isPreviewable(),
        ])->title($this->movieId === null ? 'Nowy film' : 'Edycja filmu');
    }

    /** @return list<string> gatunki bez pustych i powtórzeń (bez względu na wielkość liter) */
    private function parsedGenres(): array
    {
        $unique = [];

        foreach (explode(',', $this->genres) as $genre) {
            $genre = trim($genre);

            if ($genre !== '' && ! isset($unique[mb_strtolower($genre)])) {
                $unique[mb_strtolower($genre)] = $genre;
            }
        }

        return array_values($unique);
    }
}

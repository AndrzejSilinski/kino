<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Artykuł, którego nie da się zapisać w tej postaci (Etap 7, blok M).
 *
 * 422: problem w danych formularza. field() mówi komponentowi, przy którym polu
 * pokazać komunikat — jak InvalidScreeningException.
 */
final class InvalidArticleException extends CinemaException
{
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly string $field,
    ) {
        parent::__construct($message);
    }

    public static function premiereWithoutMovie(): self
    {
        return new self('Artykuł o premierze musi wskazywać film.', 'premiere_without_movie', 'movieId');
    }

    public static function unknownMovie(): self
    {
        return new self('Wybrany film nie istnieje.', 'unknown_movie', 'movieId');
    }

    public static function badPublishTime(string $message): self
    {
        return new self($message, 'bad_publish_time', 'publishDate');
    }

    public function field(): string
    {
        return $this->field;
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'ARTICLE_INVALID';
    }

    public function context(): array
    {
        return ['reason' => $this->reason, 'field' => $this->field];
    }
}

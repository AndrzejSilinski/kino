<?php

/*
 * Polskie komunikaty walidacji.
 *
 * Plik jest CELOWO niepełny — zawiera tylko reguły, których faktycznie
 * używa nasze API. Dla brakujących kluczy Laravel sięga po fallback_locale
 * ('en'), więc nic się nie wywali, a plik nie udaje pełnego tłumaczenia
 * frameworka i da się go przeczytać w całości.
 *
 * :attribute podstawia nazwę pola z sekcji 'attributes' na końcu pliku.
 */

return [
    'required' => 'Pole :attribute jest wymagane.',
    'string' => 'Pole :attribute musi być tekstem.',
    'integer' => 'Pole :attribute musi być liczbą całkowitą.',
    'numeric' => 'Pole :attribute musi być liczbą.',
    'boolean' => 'Pole :attribute musi mieć wartość prawda albo fałsz.',
    'array' => 'Pole :attribute musi być listą.',
    'email' => 'Pole :attribute musi być poprawnym adresem e-mail.',
    'unique' => 'Ta wartość pola :attribute jest już zajęta.',
    'exists' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
    'confirmed' => 'Powtórzone pole :attribute nie jest takie samo.',
    'distinct' => 'Pole :attribute zawiera powtórzoną wartość.',
    'date' => 'Pole :attribute musi być poprawną datą.',
    'date_format' => 'Pole :attribute musi mieć format :format.',
    'in' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
    'regex' => 'Format pola :attribute jest nieprawidłowy.',

    'min' => [
        'numeric' => 'Pole :attribute nie może być mniejsze niż :min.',
        'string' => 'Pole :attribute musi mieć co najmniej :min znaków.',
        'array' => 'Pole :attribute musi zawierać co najmniej :min elementów.',
    ],

    'max' => [
        'numeric' => 'Pole :attribute nie może być większe niż :max.',
        'string' => 'Pole :attribute nie może mieć więcej niż :max znaków.',
        'array' => 'Pole :attribute nie może zawierać więcej niż :max elementów.',
    ],

    // Klucze reguły Password::min(...)->letters()->numbers().
    // Metoda messages() w FormRequest ich NIE dosięga — dlatego są tutaj.
    'password' => [
        'letters' => 'Hasło musi zawierać przynajmniej jedną literę.',
        'mixed' => 'Hasło musi zawierać wielką i małą literę.',
        'numbers' => 'Hasło musi zawierać przynajmniej jedną cyfrę.',
        'symbols' => 'Hasło musi zawierać przynajmniej jeden znak specjalny.',
        'uncompromised' => 'To hasło pojawiło się w znanym wycieku danych. Wybierz inne.',
    ],

    /*
     * Nazwy pól podstawiane pod :attribute. Bez tej sekcji użytkownik
     * zobaczyłby "Pole seat_ids jest wymagane" — czyli nazwę kolumny
     * z bazy zamiast nazwy zrozumiałej dla człowieka.
     */
    'attributes' => [
        'name' => 'imię i nazwisko',
        'email' => 'adres e-mail',
        'password' => 'hasło',
        'device_name' => 'nazwa urządzenia',
        'seat_ids' => 'lista miejsc',
        'date' => 'data',
    ],
];

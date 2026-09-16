{{-- Wspólna sekcja <head> panelu: layout stron Livewire i strona logowania. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{{-- Token CSRF dla skryptów panelu (Echo w bloku L); formularze mają własne @csrf. --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
{{-- Panel nie trafia do wyszukiwarek. --}}
<meta name="robots" content="noindex, nofollow">
<title>{{ isset($title) ? $title.' · ' : '' }}Panel kina</title>
{{-- Zasoby lokalne i przypięte (tools/admin-assets): bez CDN i bez kroku budowania. --}}
<link rel="stylesheet" href="{{ asset('vendor/admin/pico.min.css') }}">
{{-- Skrypty strony (np. Echo na pulpicie, blok L) — ładowane PRZED skryptem Livewire z końca <body>. --}}
@stack('head')

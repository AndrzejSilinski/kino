<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Zaufane proxy (Etap 10, blok E)
|--------------------------------------------------------------------------
|
| Middleware TrustProxies z Laravela czyta tę wartość przy każdym żądaniu. Nagłówki
| X-Forwarded-For/-Proto/-Host/-Port są brane pod uwagę TYLKO wtedy, gdy żądanie
| przyszło z adresu z tej listy — inaczej każdy klient mógłby podać sobie dowolne IP
| (limity logowania i blokad miejsc liczone po IP) i udawać HTTPS.
|
| Domyślnie pusto: w stosie z docker-compose.yml TLS kończy nginx, który przekazuje
| żądanie do PHP-FPM przez FastCGI razem z HTTPS=on — Laravel widzi https:// bez
| żadnego proxy. Lista jest potrzebna dopiero za zewnętrznym load balancerem albo CDN,
| które kończą TLS przed nginx: adresy (albo zakresy CIDR) po przecinku, np.
| TRUSTED_PROXIES=10.0.0.0/8. "*" ufa każdemu adresowi — tylko gdy do aplikacji nie da
| się dotrzeć z pominięciem proxy.
|
*/

return [

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];

# Etap 9 — notatki robocze: decyzje i pułapki

Plik prowadzony **na bieżąco, blok po bloku** (zasada 32). Każda paczka bloku dopisuje tu swoje
decyzje i pułapki, a blok README tylko je redaguje do sekcji Etapu 9 w `README.md`.

Powód: w Etapie 8 rozmowa została automatycznie skrócona i treść decyzji z czatu przepadła —
numerację trzeba było odtwarzać z kodu. Repozytorium pamięta, czat nie.

Numeracja ciągła z poprzednich etapów: **decyzje od 252**, **pułapki od CE**.

---

## Blok A — narzędzia (wykonawca paczek, kontener Fluttera, telefon)

### Decyzje

**252. Flutter i Android SDK wyłącznie w kontenerze, w obrazie własnym na bazie przypiętej po
digeście.** `docker/flutter/Dockerfile` bierze `gmeligio/flutter-android:3.47.4`
(`sha256:ae5cb8df…5557`, Flutter 3.47.4, Dart 3.13.3, Android SDK 36 + build-tools 36.0.0,
JDK 17, NDK 28.2.13676358, linux/amd64) i zmienia w niej jedną rzecz: prawa odczytu do SDK
dla dowolnego uid. Obraz lokalny nazywa się `cinema/flutter:3.47.4`, tak jak `cinema/php:dev`.

Wariantów było trzy: instalacja Fluttera w WSL, Android Studio na Windowsie, kontener.
Wybrany jest kontener, bo daje te same wersje u mnie, w wykonawcy paczek i w CI Etapu 10,
a niczego nie instaluje w systemie. Cena: **brak hot reload** — każda zmiana wymaga buildu APK
i `adb install -r`. Gdyby praca nad planem sali okazała się z tego powodu zbyt wolna,
dołożymy Fluttera na Windowsie *wyłącznie* do `flutter run`, zostawiając kontener jako
jedyne źródło prawdy dla analizy, testów i buildów.

Odrzucone: `ghcr.io/cirruslabs/flutter` — repozytorium przestało wydawać obrazy w maju 2026
i nie ma wydania 3.47 (ostatnie 3.44.0); `instrumentisto/flutter` — zarchiwizowane.

**253. SDK Fluttera w wolumenie `cinema_flutter_sdk`, z właścicielem uid użytkownika WSL.**
Wolumen zasiewa się zawartością katalogu z obrazu przy pierwszym montowaniu, a potem
jednorazowy kontener roota zmienia właściciela. Powód w pułapce CH: Gradle musi móc pisać
wewnątrz SDK. Android SDK zostaje w obrazie **tylko do odczytu** — build debug APK tego nie
potrzebuje (sprawdzone: `ZAPIS_ANDROID_SDK=nie`, build zakończony powodzeniem).

**254. HOME kontenera w wolumenie `cinema_flutter_home`:** `PUB_CACHE`, `GRADLE_USER_HOME`
i `~/.android`. Ostatni katalog trzyma **stały klucz debug**. Bez niego każdy świeży kontener
podpisywałby APK nowym kluczem, a `adb install -r` odrzucałby aktualizację z
`INSTALL_FAILED_UPDATE_INCOMPATIBLE` i wymuszał odinstalowanie aplikacji razem z jej danymi.

**255. Na Windowsie tylko Android SDK Platform-Tools (sam `adb`).** Bez Android Studio i bez
emulatora: testujemy na telefonie, a emulator z obrazem Google Play byłby kilkunastoma GB
i drugim źródłem różnic. `tools/mobile/telefon.ps1` obsługuje stan urządzenia, przekierowanie
portu, instalację APK i logi.

**256. Sieć telefon → aplikacja przez `adb reverse tcp:8080 tcp:8080`** oraz adres API z
konfiguracji buildu (`--dart-define=API_BASE_URL=http://localhost:8080`), nie ze stałej w kodzie.
Dzięki temu telefon widzi `localhost:8080` tak jak przeglądarka, więc **kontrakt API i `APP_URL`
zostają bez zmian**, łącznie z adresami absolutnymi plakatów, avatara i `qr_url`, a WebSocket
łączy się z tym samym hostem. Nie otwieramy portów w zaporze Windows ani nie robimy portproxy
do WSL2, nie zmieniamy też adresów w API na relatywne. Weryfikacja w bloku C.

**257. Wykonawca paczek `etap9_blok.sh` obejmuje `mobile/` i ma tryb `--proba`.** Kroki dla
aplikacji: `pub get --enforce-lockfile`, `dart format --set-exit-if-changed`, `flutter analyze`,
`flutter test --machine` z oczekiwaną liczbą testów i opcjonalny build APK kopiowany na Pulpit.
Tryb `--proba <BLOK>` wykonuje wszystkie kontrole bez commita i sam wycofuje zmiany — jest
potrzebny, bo środowisko Claude'a nie ma dostępu do pub.dev ani do SDK Fluttera, więc
**testy Darta pierwszy raz uruchamiają się u mnie**, a nie u niego. Testy PHP Claude
uruchamia u siebie przed wysłaniem paczki.

**258. Liczby testów Fluttera bierzemy z raportu `flutter test --machine`** (zdarzenia
`testDone` z `result: success` i bez `hidden`), zapisywanego do `mobile/build/etap9/test.json`.
Tak samo jak raport Vitest z Etapu 8 leży poza gitem, a skrypt zgodności README (zasada 29)
czyta go z tego samego miejsca.

### Pułapki

**CE. `local A=$X B="$A/…"` w jednym poleceniu.** Bash rozwija wszystkie słowa, **zanim**
`local` przypisze `A`, więc `B` powstaje ze starej (często pustej) wartości i ścieżki robią się
z korzenia dysku. Każde `local`, które używa wcześniejszego `local`, musi być w osobnej linii.

**CF. `plainTextToken` Sanctuma ma postać `id|48 znaków`, nie `id|40`.** Wzorzec `\d+\|\w{40}`
cicho obcina token do nieprawidłowej wartości, a API odpowiada 401 — co wygląda jak błąd
autoryzacji, a jest błędem wyrażenia regularnego. W skryptach dopasowujemy `{40,64}`.
Skaner sekretów zostaje przy `{40}`, bo trafia w pierwsze 40 znaków dłuższego tokenu.

**CG. `/home/flutter` w obrazie ma prawa 700.** Uruchomiony jako inny uid Flutter kończy się
`flutter: Permission denied` (EXIT 127). Dodanie grupy obrazu (`--group-add 1001`) nie pomaga,
bo prawa 700 nie dają nic grupie. Rozwiązanie: cienki obraz pochodny, który nadaje prawa
odczytu katalogom i plikom SDK; koszt to warstwa metadanych ok. 50 KB, nie kopia 3 GB.

**CH. Katalog SDK musi być zapisywalny, inaczej `flutter build apk` pada bez żadnych
szczegółów.** Szablon Fluttera dołącza budowę Gradle przez
`includeBuild("$flutterSdkPath/packages/flutter_tools/gradle")`, a Gradle zakłada dla niej
`.gradle/` i `build/` **w katalogu tej budowy**, czyli wewnątrz SDK; `GRADLE_USER_HOME` tego nie
przenosi. Przy SDK tylko do odczytu jedyny komunikat to
`FAILURE: Build failed with an exception. * What went wrong: The settings are not yet available
for build.` — pochodzi z `DefaultGradle.getSettings()` i nie mówi nic o prawach do plików.
Dodatkowy objaw: `android/gradle.properties` zostaje wtedy obcięty do jednej linii i gubi flagi
szablonu (`android.useAndroidX`, `android.newDsl=false`, `android.builtInKotlin=false`), co
myląco wygląda na niekompletny szablon. Po udostępnieniu SDK do zapisu plik jest kompletny,
a build kończy się `✓ Built build/app/outputs/flutter-apk/app-debug.apk`.

### Zmierzone (stan na blok A)

| Co | Wartość |
|---|---|
| Flutter / Dart | 3.47.4 / 3.13.3 |
| Szablon Androida | minSdk 24, targetSdk 36, compileSdk 36 |
| Narzędzia szablonu | AGP 9.1.0, Gradle 9.3.1, Kotlin 2.4.0, JDK 17 |
| `applicationId` projektu próbnego | `pl.silinski.cinema` |
| Pierwszy `flutter build apk --debug` | 145 s (kolejne krócej, Gradle w wolumenie) |
| Rozmiar debug APK szablonu | 150 MB |
| Wolumeny | `cinema_flutter_sdk` ok. 1,9 GB, `cinema_flutter_home` rośnie z Gradle |

---

## Blok C — zależności, konfiguracja natywna, pierwszy APK na telefonie

### Decyzje

**259. Wszystkie natywne wtyczki dodane w jednym bloku, zanim powstanie kod, który ich
używa.** `flutter_stripe`, `firebase_core`, `firebase_messaging`, `flutter_local_notifications`,
`image_picker`, `flutter_image_compress`, `share_plus`, `path_provider` — świadomy „spike”.
Powód: niezgodności z AGP 9 i Gradle 9.3.1 wychodzą przy budowaniu APK, nie przy pisaniu Darta.
Lepiej stracić jeden blok na Gradle teraz niż odkryć to w bloku M, mając za sobą osiem bloków
kodu. Wersje przypięte dokładnie, każda sprawdzona na pub.dev tego dnia (wydawca, licencja,
data wydania, wymagana wersja Fluttera, minSdk).

**260. Modele JSON pisane ręcznie, bez `build_runner`.** `freezed` i `json_serializable`
wymagałyby generowania kodu w wykonawcy paczek (albo commitowania plików `*.g.dart`), a każdy
wygenerowany plik trzeba by i tak czytać przed rozmową. Zamiast tego jedno miejsce z
pomocnikami (`lib/core/json.dart`): każde pole przechodzi przez strażnika, a brak pola albo
inny typ daje `INVALID_RESPONSE` z dokładną ścieżką (`client-config.booking.max_seats_per_session`).

**261. Klient HTTP: `http` + własna warstwa jako port `frontend/src/api/http.ts`.** `dio` daje
interceptory, których nie potrzebujemy, a własna warstwa jest tym, co trzeba umieć wyjaśnić:
koperta `data`, rozgałęzianie po `code`, kody tylko klienta (`NETWORK_ERROR`,
`INVALID_RESPONSE`), `Retry-After` przy 429. Testy używają `MockClient` z `package:http/testing.dart`,
więc żaden test nie dotyka sieci.

**262. Adres API z parametru buildu (`--dart-define=API_BASE_URL`), nigdy ze stałej w kodzie.**
`AppConfig.parseBaseUrl` odrzuca adres bez `http`/`https`, bez hosta i z parametrami zapytania,
więc literówka w parametrze buildu wychodzi przy starcie. Do tego `isSameOrigin`: token bearer
wolno dołączyć tylko do adresu z tego samego originu co API (dotyczy `qr_url` i PDF-ów).

**263. Riverpod 3 bez generatora.** Providery pisane ręcznie w `lib/state/providers.dart`,
w testach podmieniane przez `overrides`. Odpowiednik Pinii z Etapu 8 plus wstrzykiwanie
zależności, bez `build_runner` w wykonawcy.

**264. Ekran diagnostyczny jako pierwszy ekran aplikacji.** Pokazuje adres API, czy połączenie
jest bez TLS i parametry z `GET /client-config` (TTL blokady, limit miejsc, okno płatności,
push włączony). Trzy rzeczy sprawdzone naraz: parametr buildu, ruch HTTP z gniazd Darta i
kształt koperty. Ekran zostaje w aplikacji jako pomoc przy pracy z telefonem — od razu widać,
do jakiego serwera mówi dane APK.

**265. Analiza w trybie ścisłym**: `strict-casts`, `strict-inference`, `strict-raw-types` plus
`unawaited_futures`, `avoid_dynamic_calls` i `prefer_single_quotes`. Wykonawca uruchamia
`flutter analyze --fatal-infos`, więc każda podpowiedź blokuje commit.

**266. Token Sanctum w `flutter_secure_storage` (Keystore) i `android:allowBackup="false"`.**
`SharedPreferences` to zwykły plik XML w katalogu aplikacji, który trafia do kopii zapasowej
Google — token ważny 30 dni nie ma prawa wyjechać z telefonu w kopii. Kod korzystający
z magazynu wchodzi w bloku D, ale flaga manifestu należy do konfiguracji natywnej.

**267. Motyw AppCompat i `FlutterFragmentActivity`** — wymagania `flutter_stripe` 14.
Zmieniamy oba motywy szablonu (jasny i ciemny), bo PaymentSheet pokazuje się jako fragment
w naszej aktywności. Wymóg widać dopiero w czasie działania aplikacji, więc wpisujemy go
od razu, razem z regułami ProGuard pod release w Etapie 10.

**268. `uses-permission INTERNET` w manifeście głównym.** Szablon Fluttera deklaruje je tylko
w wariancie debug (dla narzędzia). Aplikacja rozmawia z API też w release.

**269. Wtyczka Google Services stosowana warunkowo.** `google-services.json` jest poza gitem
(decyzja 292 z planu), a `app/build.gradle.kts` sprawdza obecność pliku: bez niego build
przechodzi i wypisuje ostrzeżenie, a push jest nieaktywny. Dzięki temu bloki C–L budują się
bez konta Firebase, a w Etapie 10 plik wjeżdża z sekretu CI.

**270. Automatyczne ponawianie providerów wyłączone globalnie** (`ProviderScope(retry: noRetry)`).
Dwa powody. Po pierwsze użytkownik ma zobaczyć komunikat i przycisk „Spróbuj ponownie”, a nie
kółko przez ponad minutę — to samo zachowanie co w SPA. Po drugie ciche powtórki biłyby
w limity zapytań serwera: blokowanie miejsc ma 30 na minutę, logowanie 5 na minutę, a przy
429 i tak dostajemy `Retry-After`, który pokazujemy użytkownikowi. Ponawiamy wyłącznie na
jego żądanie.

**271. Android SDK w wolumenie zapisywalnym dla naszego uid** (`cinema_flutter_android`), a nie
tylko do odczytu z obrazu. Wtyczki natywne kompilują się wobec starszych platform niż ta
w obrazie (`firebase_core` wymaga `platforms;android-34`), a Gradle dociąga je w trakcie buildu.
Obraz zostaje przypięty po digeście; zmienne jest tylko to, co Gradle sam doinstaluje — a listę
faktycznie potrzebnych platform wpiszemy na stałe do `docker/flutter/Dockerfile` w Etapie 10,
gdy CI będzie budować bez wolumenów.

### Pułapki

**CI. `errors:` w `analysis_options.yaml` nie włącza reguły, tylko zmienia jej wagę.** Reguła
niewymieniona w `linter: rules:` (ani nie wniesiona przez `flutter_lints`) pozostaje wyłączona,
więc wpis `unawaited_futures: error` w sekcji `errors` nic nie robi. Reguły włączamy w `rules:`,
a `--fatal-infos` zamienia je w blokadę commita.

**CJ. `git check-ignore <katalog>` dla wzorca `/build/` działa tylko wtedy, gdy katalog
istnieje.** Wzorzec z ukośnikiem na końcu pasuje wyłącznie do katalogu, a nieistniejącej
ścieżki git nie uznaje za katalog i zwraca kod 1. Kontrola „czy `build/` jest ignorowane”
musi więc najpierw założyć katalog. Trafiło to blok B przy pierwszym przebiegu.

**CL. Zapis pliku na Pulpit i natychmiastowy odczyt potrafią się rozminąć.** Plik sum
`etap9_narzedzia.sha256` raz został na Pulpicie w starej wersji mimo potwierdzonego zapisu,
a `etap9_blok.sh` odczytany tuż po zapisie pokazywał jeszcze poprzednią treść. Stąd zasada:
po każdym zapisie na Pulpit sprawdzamy sumę PO STRONIE PULPITU, z chwilą przerwy, zanim
paczka pójdzie do uruchomienia (rozwinięcie zasady 33).

**CM. Riverpod 3 sam ponawia nieudane providery, a w trakcie ponawiania stan jest oznaczony
jako ładowanie.** Domyślnie 10 prób z narastającym opóźnieniem od 200 ms do 6,4 s, a
`await ref.watch(provider.future)` czeka do wyczerpania wszystkich prób. Efekt w aplikacji:
ekran pokazuje kręcące się kółko zamiast komunikatu o błędzie, a test widgetu „pokaż błąd
i pozwól ponowić” nie znajduje żadnego komunikatu — co wygląda na błąd testu, a jest
zachowaniem biblioteki. Rozwiązanie w decyzji 270.

**CN. Android SDK tylko do odczytu zatrzymuje build na wtyczkach natywnych.** Komunikat jest
konkretny, ale łatwo go wziąć za problem z siecią:
`Failed to install the following SDK components: platforms;android-34 / The SDK directory is not
writable (/home/flutter/sdks/android-sdk)`. Winna nie jest wtyczka ani wersja AGP, tylko prawa
do katalogu SDK. Rozwiązanie w decyzji 271.

**CO. Build Gradle zostawia `mobile/android/.kotlin/sessions/…`,** czego szablon Fluttera nie
ignoruje. Po każdym buildzie repozytorium przestaje być czyste i kolejny blok zatrzymuje się na
strażniku. Katalog dopisany do `mobile/android/.gitignore`.

**CK. Szablon Fluttera trzyma `gradlew`, `gradlew.bat` i `gradle-wrapper.jar` POZA gitem**
(`mobile/android/.gitignore`) i odtwarza je przy buildzie. CI w Etapie 10 nie może więc
wywołać `./gradlew` z repozytorium — musi iść przez `flutter build`.

---

## Blok D — rdzeń: konto, sesja, nawigacja, czas i pieniądze

### Decyzje

**272. Token bearer i sesja zakupowa wędrują przez jeden obiekt `ApiSession`.** Klient API nie
zna magazynu ani logiki logowania: pyta sesję o token i identyfikator koszyka, a przy 401
`UNAUTHENTICATED` woła `onTokenRejected()`. Dzięki temu wygasły token znika z telefonu przy
pierwszym odrzuconym żądaniu, a nie dopiero przy następnym otwarciu ekranu konta. Kod klienta
zostaje testowalny bez wtyczek natywnych — testy podstawiają atrapę sesji.

**273. Ścieżki nawigacji identyczne jak w SPA** (`/`, `/login`, `/register`, plus `/diagnostics`
tylko w aplikacji). Powiadomienie push niesie `data.url` w postaci `/bookings/{reference}` —
te same ścieżki znaczy brak tłumaczenia adresów przy deep linkach w bloku M i jeden format
w mailu, w SPA i w aplikacji.

**274. Sesja zakupowa jest jedna na instalację i leży w Keystore obok tokenu.** W SPA jest jedna
na kartę przeglądarki (użytkownik może mieć dwa koszyki), na telefonie taki przypadek nie
istnieje. Stały identyfikator pozwala wrócić do porzuconego koszyka po zamknięciu aplikacji —
blokady miejsc należą do sesji, nie do konta, więc wylogowanie ich nie kasuje.

**275. Wylogowanie jest lokalne nawet bez sieci.** Najpierw próbujemy `POST /auth/logout`
(kasuje token tego urządzenia i przez kaskadę jego urządzenie push), ale błąd nie zatrzymuje
czyszczenia magazynu. Token na serwerze wygaśnie sam po 30 dniach. Ograniczenie opisujemy
w README.

**276. Reguły hasła pilnuje serwer.** Formularz sprawdza tylko rzeczy oczywiste (pola niepuste,
hasła zgodne), a komunikaty `errors` z 422 pokazujemy przy polach. Dwa zestawy reguł
rozjechałyby się przy pierwszej zmianie polityki haseł w backendzie.

**277. `CinemaTime` zamiast `DateTime`.** Godziny seansów przychodzą w strefie kina; typ trzyma
osobno czas ścienny (do wyświetlenia dosłownie) i przesunięcie strefy (do porównań przez
`instant`). `Money` trzyma grosze i gotowy napis z serwera — aplikacja nigdy nie formatuje kwot
sama.

### Pułapki

**CP. `DateTime.parse` gubi strefę kina.** `DateTime.parse('2026-09-18T19:30:00+02:00')` daje
moment w UTC, a `toLocal()` przelicza go na strefę TELEFONU. Użytkownik w innej strefie
zobaczyłby inną godzinę seansu niż ta na bilecie. Dlatego czas ścienny parsujemy sami
i trzymamy w `DateTime.utc(...)` wyłącznie jako pojemnik na pola, bez przeliczeń.

**CQ. Strażnik w teście dymnym trafił we własny komentarz.** Reguła „w `lib/` nie ma
`DateTime.parse` ani `toLocal()`” wyłapała zdanie z `cinema_time.dart`, które tłumaczy,
dlaczego ich nie używamy. Wzorce szukające zakazanych wywołań muszą pomijać linie
komentarza — inaczej dokumentacja decyzji psuje kontrolę tej decyzji.

**CS. `Override` z Riverpoda nie jest typem do wpisania w kodzie** (biblioteka go nie
eksportuje), a `flutter analyze` mówi o tym dopiero przy `List<Override>`. Listę overrides
zostawiamy bez jawnego argumentu typu — wnioskuje się z elementów. Ta sama pomyłka trafiła
mnie w blokach C i D.

**CT. W Darcie znacznik null-aware przy kluczu to co innego niż przy wartości.**
`?klucz: wartość` pomija wpis, gdy null jest kluczem; przy nullowalnej wartości poprawne jest
`klucz: ?wartość`. Pomyłka daje dwa komunikaty naraz: „operator ? niepotrzebny przy kluczu”
oraz „String? nie pasuje do String”.

**CR. Odczyt tokenu z Keystore wyścigał się z ręcznym logowaniem.** `restore()` startuje przy
budowie kontrolera i kończy się po kilkudziesięciu milisekundach — jeśli w tym czasie
użytkownik zdążył się zalogować, ustawiał stan „niezalogowany” na świeżym stanie. Stąd
strażnik: `restore()` nie nadpisuje stanu, gdy ktoś jest już zalogowany.

---

## Blok E — katalog: kina, dni, repertuar, seans

### Decyzje

**278. Klient API ma osobne metody dla koperty obiektu i koperty listy**
(`getJson`, `getList`, `getEnvelope`). Listy katalogu zwracają `data` jako tablicę, a odczyty
obiektów jako mapę. Jedna metoda „uniwersalna” oznaczałaby rzutowanie w każdym repozytorium
i błąd `INVALID_RESPONSE` dopiero w czasie działania. `getEnvelope` oddaje też `links` i `meta`,
bez czego nie da się obsłużyć paginacji.

**279. Wybór kina zapamiętany w tym samym magazynie co token.** To nie jest sekret, ale
dokładanie `shared_preferences` tylko dla jednego sluga oznaczałoby kolejną wtyczkę natywną
i drugie miejsce, w którym dane zostają po wylogowaniu. Ekran główny prowadzi wprost do
repertuaru zapamiętanego kina, a listę miast pokazuje tylko przy pierwszym uruchomieniu.

**280. O tym, czy seans można kupić, decyduje wyłącznie serwer** (`is_bookable`, `is_sold_out`,
`has_started`). Aplikacja nie liczy tego z godziny ani z liczby wolnych miejsc — inaczej przy
zmianie reguł (bufor przed seansem, seans odwołany, sprzedaż wstrzymana) telefon pokazywałby
co innego niż web. Powód niedostępności zamieniamy na krótki napis na karcie i w `Semantics`.

**281. Repertuar dnia dobiera kolejne strony.** Lista ma 50 pozycji na stronę; przy dużym kinie
jeden dzień może się nie zmieścić. Pętla po stronach ma bezpiecznik (5 stron), żeby błąd
serwera w `meta.last_page` nie zapętlił aplikacji.

**282. Wspólny `AsyncView` dla ładowania i błędu.** Odkąd automatyczne ponawianie jest wyłączone
(decyzja 270), KAŻDY ekran z danymi z sieci musi mieć komunikat i przycisk „Spróbuj ponownie”.
Jeden widget zamiast powtarzania tego na każdym ekranie.

**283. Plakat zawsze ma zastępnik, a zastępnikiem jest ikona.** W danych deweloperskich
`poster_url` bywa `null`, a na telefonie obraz może się nie pobrać (brak sieci, zerwany tunel
`adb`). Widget `Poster` pokazuje wtedy ikonę filmu na tle — lista nigdy się nie wywraca i nie
zostawia pustej dziury. Pierwsza wersja wypisywała w ramce tytuł; zrezygnowaliśmy z tego, bo
tytuł stoi zawsze bezpośrednio obok plakatu (karta seansu, nagłówek szczegółów), więc napis
w środku ramki dublował go na ekranie, w czytniku ekranu i w wyszukiwaniu widgetów w testach
(pułapka CW). Plakat jest dekoracją: nie wnosi własnej semantyki, bo cały opis pozycji składa
`Semantics` karty.

### Pułapki

**CU. Rodzina providerów Riverpoda porównuje klucze przez `==`.** Klucz złożony (kino + dzień)
musi mieć równość po wartości i `hashCode`, inaczej każde wejście na ekran tworzy nowy provider
i nowe żądanie do API. Stąd klasa `DaySchedule` z ręcznie napisanym `==`.

**CV. Nazwy klas rodzin providerów różnią się między wersjami Riverpoda.** Adnotacja typu
(`FutureProviderFamily<…>`) potrafi się nie skompilować po aktualizacji, choć `FutureProvider.family`
działa dalej. Rodziny zostawiamy bez jawnego argumentu typu — wnioskowanie jest odporne.

**CW. `find.text('tytuł')` trafia w każdy napis, także w ten w zastępniku plakatu.** Dwa testy
repertuaru padły (`Found 2 widgets with text "Oppenheimer"` i `Bad state: Too many elements`),
bo zastępnik plakatu wypisywał tytuł filmu, a w teście widgetów `Image.network` nigdy nie
pobiera obrazu — zastępnik pokazuje się więc w KAŻDEJ pozycji listy, nie tylko tam, gdzie
`poster_url` jest `null`. Dwie nauki: (1) w teście widgetów obrazy z sieci nigdy się nie
wczytują, więc zawsze widać gałąź zastępnika, (2) test, któremu chodzi o pozycję listy, ma jej
szukać po strukturze — `find.widgetWithText(ListTile, 'tytuł')` zamiast `find.text('tytuł')`
albo `find.ancestor(…)` — bo wtedy nie rozsypie się od dodania gdziekolwiek drugiego napisu
z tym samym tekstem. Sam napis w zastępniku i tak usunęliśmy (decyzja 283), bo dublował tytuł
stojący obok.

**CX. Wzorzec testu dymnego widzi PLIK, nie zachowanie programu.** Sprawdzenie „trasa kina ze
slugiem" szukało napisu `cinemas/:slug`, a w kodzie ścieżka składa się z interpolacji
(`'${Routes.cinemas}/:slug([a-z0-9-]+)'`) — w pliku takiego ciągu po prostu nie ma, więc test
dymny wywalił blok, mimo że trasa działa i przechodzą ją testy widgetów. Wniosek: wzorzec pisze
się po zajrzeniu do gotowego pliku, a nie z pamięci o tym, jak ścieżka wygląda po złożeniu.
Przy okazji sprawdzamy też ograniczenia parametrów (`[a-z0-9-]+`, `\d+`) — to one pilnują, żeby
adres z powiadomienia albo z linku nie wpuścił do aplikacji śmieci.

## Blok F — plan sali i koszyk (REST)

Rozpoznanie fazy 2 (`etap9_R2_api.txt`, 33 sprawdzenia, 0 błędów) potwierdziło na żywym serwerze
cały kontrakt koszyka: kształt planu sali, kształt koszyka, konflikt 409 z listą miejsc, kody
błędów walidacji, zachowanie sesji zakupowej, niezależność limitu 30/min per sesja oraz payload
zdarzenia `seats.changed`. Poniższe decyzje wynikają z tego, co serwer naprawdę robi, a nie
z tego, jak go pamiętałem.

### Decyzje

**284. Nieznany status miejsca to `unavailable`, nie wyjątek.** Serwer zna dziś pięć statusów
(`free`, `held`, `held_by_you`, `sold`, `unavailable`). Gdyby doszedł szósty, aplikacja w starej
wersji ma pokazać plan sali z jednym fotelem nie do kupienia, a nie ekran błędu. Bezpieczny
domyślny wybór przy nieznanej wartości to zawsze „nie wolno sprzedać”. Uwaga: to jedyne miejsce,
gdzie odstępujemy od zasady „nieznany kształt = INVALID_RESPONSE” — bo tu chodzi o WARTOŚĆ pola
o znanym typie, a nie o brakujące pole.

**285. O MOICH miejscach rozstrzyga koszyk, o cudzych plan sali.** Rozpoznanie pokazało, że
zdarzenie `seats.changed` opisuje moje miejsca jako `held` — i słusznie, bo payload nie może
zależeć od tego, kto słucha (inaczej zdradzałby cudzy koszyk). Gdyby ekran malował status wprost
ze zdarzenia, po cudzym kliknięciu moje fotele zmieniłyby kolor na „zajęte przez kogoś innego”.
Dlatego identyfikatory z koszyka mają pierwszeństwo. To samo rozwiązuje drugi przypadek: po
zwolnieniu miejsca plan sali z REST-a wciąż pamięta moją blokadę, a koszyk już nie — fotel
pokazujemy jako wolny, bez ponownego pobierania planu.

**286. Miejsca odrzucone przez 409 malujemy z `context.seat_ids`.** Serwer przysyła listę zajętych
foteli właśnie po to, żeby klient nie musiał pobierać całego planu sali. Wykorzystujemy to: 409
nie jest komunikatem „coś się nie udało”, tylko informacją, którą nakładamy na plan.

**287. Koszyk zawsze bierzemy z ODPOWIEDZI, nigdy z domysłu.** Każda operacja (GET, POST, DELETE
jednego miejsca, DELETE całości) zwraca pełny koszyk z wyceną. Blokada jest all-or-nothing, więc
optymistyczne dodanie miejsca do koszyka byłoby błędne w każdym przypadku konfliktu. Podnosimy
tylko znacznik „w trakcie” na jednym fotelu, a stan zastępujemy tym, co przyszło z serwera.

**288. Czas koszyka odliczamy z `expires_in_seconds`, nie z różnicy dat.** Zegar telefonu bywa
przestawiony, a `lock_expires_at` przychodzi w UTC (pułapka CY). Datą klient się resynchronizuje,
sekundami odlicza — dokładnie tak, jak to opisuje kontrakt serwera.

**289. Żadnych automatycznych ponowień na blokadach.** Limit to 30 żądań na minutę liczonych
sesją zakupową, a rozpoznanie potwierdziło, że zużywają go także odpowiedzi odrzucone (`Remaining`
spada przy 422). Ciche powtórki po 409 czy 422 wyczerpałyby limit dokładnie w momencie premiery.
Ponowienie jest zawsze kliknięciem użytkownika.

**290. Rodzina providerów Riverpoda 3 przekazuje argument KONSTRUKTOREM.** Sprawdzone w źródle
wersji 3.4.3, nie w poradnikach: `AsyncNotifierProvider.family<NotifierT, StateT, ArgT>` przyjmuje
`NotifierT Function(ArgT)`, a `build()` jest bezargumentowe. Klas `FamilyAsyncNotifier` z wersji 2
w ogóle już nie ma (pułapka DB).

**291. Plan sali pobieramy PRZED koszykiem.** Trasa planu sali nie ma limitu zapytań (celowo — po
zerwaniu WebSocketa klient dobiera pełny stan przez REST) i to ona wydaje sesję zakupową, gdy
aplikacja jeszcze jej nie ma. Odwrotna kolejność zużywałaby limit blokad na samo wydanie sesji.

### Pułapki

**CY. Jeden kontrakt, dwie strefy czasowe.** Godziny seansu przychodzą w strefie kina
(`+02:00`), a czasy blokad koszyka w UTC (`2026-09-28T15:40:52+00:00`). Oba zapisy są poprawne
i wskazują ten sam moment, ale gdyby ktoś wyświetlił `lock_expires_at` przez `wallClock`,
pokazałby klientowi godzinę o dwie mniejszą. Do odliczania służy `expires_in_seconds`.

**CZ. POST blokady bez nagłówka `X-Session-Id` KOŃCZY SIĘ SUKCESEM.** Serwer wydaje nową sesję
i przypisuje jej blokadę — 201, pełny koszyk, wszystko w porządku, tylko że aplikacja nie wie,
w czyim koszyku wylądowało miejsce. Dlatego identyfikator z nagłówka odpowiedzi zapamiętujemy
w kliencie API (blok D) i nie polegamy na tym, że „i tak zwykle jest”.

**DA. Odpowiedź 422 `INVALID_SESSION_ID` nie ma ani nagłówka `X-Session-Id`, ani pól `context`
i `errors`.** Middleware rzuca wyjątek, zanim ustawi nagłówek. Dwa wnioski: parser błędów musi
znosić brak tych pól (znosi — `ApiError.fromBody` wymaga tylko `code` i `message`), a klient nie
może na podstawie takiej odpowiedzi „zapomnieć” swojej sesji.

**DB. Riverpod 3 nie ma klas `FamilyNotifier`/`FamilyAsyncNotifier`, a setter `state` jest
`@protected`.** Pierwsze oznacza, że argument rodziny wchodzi konstruktorem (decyzja 290). Drugie,
że test NIE może podstawić stanu notifierowi z zewnątrz, mimo adnotacji `@visibleForTesting` —
stan trzeba ustawić przez atrapę serwera (u nas: podmieniony `max_seats_per_session`
w `client-config`). Wyszło to, zanim kosztowało przebieg: sprawdziłem źródło wersji 3.4.3.

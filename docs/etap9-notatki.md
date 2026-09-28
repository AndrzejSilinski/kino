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

## Blok F2 — ekran planu sali

### Decyzje

**292. Jedno miejsce na zamianę `#RRGGBB` na kolor.** Kolory kategorii cenowych ustawia
administrator w panelu, więc aplikacja ich nie zna z góry. Pokazuje je teraz cennik seansu, fotele
na planie i legenda planu — trzy ekrany, jedna funkcja `colorFromHex` z kolorem zapasowym
z motywu. Zły albo pusty zapis nie może wywrócić ekranu: sala rysuje się dalej, tylko obwódki są
w kolorze domyślnym.

**293. Plan sali jest skalowalny (`InteractiveViewer`), a nie ściśnięty do szerokości ekranu.**
Sala na 300 miejsc w 20 kolumnach nie zmieści się czytelnie na telefonie: albo fotele robią się
za małe do trafienia palcem, albo plan wychodzi za ekran. `constrained: false` pozwala siatce być
większą niż widok, a powiększanie dwoma palcami i przesuwanie rozwiązują resztę bez własnej
matematyki. Rozmiar planu jest policzalny z góry (stały bok fotela razy liczba kolumn), więc nie
potrzeba pomiarów w czasie działania.

**294. Fotele rysujemy po WSPÓŁRZĘDNYCH, nie po kolejności listy.** Sala ma przejścia, więc
miejsce numer 8 może stać w kolumnie 9. Serwer podaje `position.x`, `position.y` i wymiary siatki
(`hall.grid`) właśnie dlatego. Rysowanie „jeden fotel po drugim” dałoby plan, który nie zgadza się
z salą — a klient wybiera miejsce patrząc na kształt sali, nie na numerację.

**295. Komunikat po odrzuconym wyborze to pasek nad planem, nie „snackbar”.** Przy 409
użytkownik ma równocześnie przeczytać komunikat i zobaczyć, że fotel zmienił kolor na zajęty.
Powiadomienie, które samo znika po trzech sekundach, gubi połowę tej informacji. Pasek ma
przycisk zamknięcia i zostaje, dopóki użytkownik go nie zamknie albo nie zrobi czegoś innego.

**296. Wolne miejsce BEZ CENY zostaje klikalne.** Blokada i tak by się nie udała (serwer odmawia,
bo kategoria nie ma ceny w cenniku seansu), ale fotel wygląda na wolny, więc klient w niego
kliknie. Ekran, który na kliknięcie milczy, wygląda na zepsuty. Kliknięcie kończy się
komunikatem — bez żądania do serwera, więc bez zużycia limitu.

**297. Wygaśnięcie licznika pobiera stan od nowa.** Licznik dochodzący do zera bez odświeżenia
zostawiłby na ekranie koszyk, którego serwer już nie ma. Wyjątek: gdy serwer PRZYSYŁA zero,
licznik nic nie zgłasza — wywołanie odświeżenia z `initState` wypadłoby w trakcie budowania
drzewa widgetów.

**298. Przycisku „dalej do płatności” w tym bloku nie ma.** Płatność to blok H razem z checkoutem
i Stripe'em. Pusty pasek koszyka jest lepszy niż przycisk, który nic nie robi — w poprzednim
bloku taki przycisk stał na ekranie seansu z napisem „wkrótce” i to był kompromis na jeden blok,
nie wzór do powtarzania.

### Pułapki

**DC. `Color.withOpacity` jest przedawnione we Flutterze 3.47.** Przy `flutter analyze
--fatal-infos` przedawnienie zatrzymuje blok, więc przezroczystość ustawiamy przez
`withValues(alpha: …)`. Wyszło to przy pisaniu, nie na przebiegu.

**DD. `firstOrNull` NIE jest w `dart:core`.** Sprawdzone w źródłach: `Iterable.firstOrNull` żyje
w rozszerzeniu z `package:collection`. Dla jednej linijki (litera rzędu z pierwszego miejsca)
nie dokładamy zależności — trzy linijki pomocnika są tańsze niż nowy wpis w `pubspec.lock`
i nowa rzecz do tłumaczenia na rozmowie.

**DE. Test z licznikiem nie wiesza `pumpAndSettle`.** Bałem się tego, bo timer cykliczny co
sekundę wywołuje `setState`. Nie wiesza, bo MIĘDZY tyknięciami nie ma zaplanowanej klatki:
`pumpAndSettle` dochodzi do stanu bez klatek i wychodzi. Czas w testach przesuwamy jawnie przez
`pump(Duration(...))`, więc nic nie czeka na realny upływ sekundy.

## Blok G1 — klient WebSocketa (protokół Pushera)

### Decyzje

**299. Klient protokołu Pushera piszemy ręcznie.** Serwerem jest Reverb, a z całego protokołu
potrzebujemy pięciu komunikatów: `pusher:subscribe`, `pusher_internal:subscription_succeeded`,
`pusher:ping`, `pusher:pong`, `pusher:error`. Gotowa paczka dołożyłaby zależność (często
z kodem natywnym), a i tak trzeba by samemu napisać to, co naprawdę trudne: podpis kanału
prywatnego z własnego API, ponawianie z narastającym odstępem i pobranie PEŁNEGO stanu sali po
zerwaniu łącza. Kształt komunikatów nie jest przepisany z dokumentacji — potwierdziło go
rozpoznanie fazy 2 na żywym Reverbie.

**300. Wszystkie czasy klienta są PARAMETRAMI, a testy chodzą realnym zegarem w milisekundach.**
Odstęp ciszy przed pingiem, okno na `pong` i pierwszy odstęp ponowienia to argumenty konstruktora
(w produkcji 30 s, 10 s i 1 s; w testach 20-40 ms). Dzięki temu cały plik testów wykonuje się
w kilkadziesiąt milisekund bez żadnego sterowanego zegara.

To druga wersja tej decyzji. Pierwsza brzmiała „testy piszemy jako `testWidgets`, bo dają
sterowany zegar bez nowej zależności” — i skończyła się dwoma testami wiszącymi po dziesięć minut
(pułapka DL). Wniosek na przyszłość: zanim sięgnę po sterowany zegar, sprawdzam, czy nie wystarczy
zrobić czasu parametrem. Wstrzyknięcie czasu jest prostsze, szybsze i nie zależy od tego, jak
konkretna strefa testowa obsługuje timery i strumienie.

**301. Gniazdo jest za interfejsem (`RealtimeSocket`), nie wołane wprost.** `web_socket_channel`
zna tylko jeden plik (`realtime_socket.dart`). Cała logika protokołu testuje się bez serwera
i bez sieci, a wymiana paczki nie dotyka logiki. To ten sam zabieg co z `SecureStore` w bloku D.

**302. Po zerwaniu łącza NIE nakładamy zaległych zdarzeń — pobieramy pełny stan przez REST.**
Nikt nam nie powtórzy zdarzeń z czasu, gdy telefon był w windzie, a `seats.changed` niesie stan
absolutny tylko WYMIENIONYCH miejsc, nie całej sali. Dlatego klient ma osobny strumień
`resubscribed`: ekran dostaje znak „odtworzyłem kanały, twój plan może być z innej epoki”
i robi jedno żądanie REST. Tego wymaga wprost sekcja 1.3 zadania, i dlatego trasa planu sali
nie ma limitu zapytań.

**303. Ping wysyłamy sami po ciszy dłuższej niż `activity_timeout` z serwera** (u nas 30 s).
Bez tego zerwane łącze — w tunelu `adb`, w sieci komórkowej, po uśpieniu telefonu — wygląda
dokładnie jak spokojne połączenie bez zdarzeń. Plan sali jest wtedy nieaktualny, a klient wybiera
miejsca, które ktoś już kupił. Brak `pong` w oknie 10 sekund traktujemy jak zerwanie.

**304. Kody błędów Pushera 4000–4099 są ostateczne.** Zły klucz aplikacji albo nieobsługiwana
wersja protokołu nie naprawią się przez ponawianie, więc klient zatrzymuje się i wystawia
`fatalError`. Kody 4100+ (np. przeciążenie) ponawiamy normalnie.

### Pułapki

**DF. `fake_async` to osobna paczka.** Sterowany zegar w zwykłym `test()` wymagałby zależności
w `pubspec.yaml`, a każda nowa zależność to wpis w `pubspec.lock`, który muszę wygenerować
u siebie — a nie mam tam Fluttera. Ostatecznie żaden sterowany zegar nie był potrzebny: czasy
są parametrami (decyzja 300).

**DK. Zamknięcie martwego gniazda WebSocketa zgłasza `onDone` na subskrypcji, którą właśnie
anulujemy.** Anulowanie jest asynchroniczne, więc zdarzenie zdąży dojść i wejść drugi raz
w obsługę zerwania. Mój pierwszy strażnik (`gniazdo == null && timer != null`) tego nie łapał,
bo timer ponowienia ustawiałem na KOŃCU metody — drugie wejście przechodziło i planowało kolejne
ponowienie obok pierwszego, bez anulowania. Rozwiązanie to licznik generacji: każde gniazdo ma
numer, a wszystko, co przychodzi ze starszego, jest ignorowane. Test „zerwanie otwiera dokładnie
jedno nowe gniazdo” pilnuje tego na stałe.

**DL. Test widgetów, który zawiśnie, kosztuje DZIESIĘĆ MINUT — tyle wynosi domyślny limit
`package:test`.** Dwa takie testy w jednym pliku zamieniły 90-sekundowy przebieg bloku w 34 minuty,
a siedem pozostałych testów w tym pliku w ogóle nie wystartowało (raport pokazał 121 testów wobec
oczekiwanych 128 — i ta rozbieżność była pierwszym sygnałem, że to nie zwykły błąd asercji).
Dwa wnioski, oba już wdrożone: krok testów w wykonawcy ma `timeout 900`, a raport wprost mówi,
gdy limit zadziałał (pułapka DJ); klient czasu rzeczywistego testujemy bez strefy testów widgetów
(decyzja 300).

**DG. `data` w protokole Pushera bywa NAPISEM z JSON-em w środku.** Ramka wygląda tak:
`{"event":"seats.changed","data":"{\"version\":5,…}","channel":"private-screenings.380"}`.
Klient, który zakłada obiekt, dostanie napis i po cichu zignoruje zdarzenie. Obsługujemy oba
kształty — rozpoznanie fazy 2 pokazywało `data` już rozpakowane, bo to skrypt je dekodował,
i o taką pomyłkę byłoby tu bardzo łatwo.

**DI. `collection-if` z porównaniem do `null` w literale mapy zawsze skończy się uwagą
`use_null_aware_elements`.** To już trzecie spotkanie z tą regułą (bloki D, E, G1), więc nie
poprawiam pojedynczego wystąpienia, tylko przestaję pisać ten wzorzec: mapę z opcjonalnymi
polami składam imperatywnie (`if (x != null) map['k'] = …`). Znacznik `?` działa tylko wtedy, gdy
wartość jest DOKŁADNIE tym samym wyrażeniem, które sprawdzamy — a w atrapach zwykle nie jest
(`data is String ? data : jsonEncode(data)`), więc analizator i tak zgłasza uwagę, a poprawka
znacznikiem nie ma jak wejść. Imperatywne złożenie mapy kończy temat raz na zawsze.

**DH. `/broadcasting/auth` zwraca odpowiedź BEZ koperty `data`.** To świadomy wyjątek od
konwencji API (opisany w kontrolerze na serwerze), bo takiego kształtu wymaga protokół Pushera
— tak samo dla `pusher-js` w SPA jak dla klienta Fluttera. Dlatego `ApiClient` ma osobną metodę
`postWithoutEnvelope`; pozostałe metody dalej rozpakowują kopertę, żeby nikt nie sięgał po `data`
ręcznie.

**DJ. Krok testów bez limitu czasu zamienia jeden błąd w pół godziny czekania.** Wykonawca
uruchamia teraz `timeout 900 … flutter test --machine` i zapisuje w raporcie, gdy limit zadziałał.
900 s to około dziesięciokrotność zdrowego przebiegu, więc poprawny zestaw nigdy tego nie dotknie,
a zapętlony test kosztuje kwadrans, nie pół dnia.

## Blok G2 — plan sali na żywo

### Decyzje

**305. Zdarzenia ze strumienia parsujemy TOLERANCYJNIE, odwrotnie niż odpowiedzi REST.**
Model REST-owy przy niezgodnym kształcie rzuca `INVALID_RESPONSE`, bo odpowiedź jest reakcją na
pytanie użytkownika i lepiej pokazać błąd niż zgadywać. Zdarzenie przychodzi samo — gdyby jedna
niezrozumiała ramka wywracała ekran wyboru miejsc, klient straciłby koszyk z powodu, na który nie
ma wpływu. Dlatego `SeatsEvent.tryParse` zwraca `null` i zdarzenie jest pomijane, a stan i tak
dociągnie pełne pobranie po REST. Test pilnuje, że ramka bez `version` nie zmienia stanu w błąd.

**306. JEDNO gniazdo na aplikację, kanały per ekran.** Protokół Pushera multipleksuje kanały na
jednym połączeniu, więc osobne gniazdo na każdy ekran to bez potrzeby kolejny handshake, kolejny
ping i kolejne wznawianie po wyjściu z tunelu. Kanał dochodzi i odchodzi razem z ekranem
(`subscribe` / `unsubscribe` w `onDispose` providera), połączenie zostaje. Adres gniazda składamy
z dwóch źródeł: host, port i schemat z parametru buildu, ścieżkę i klucz publiczny
z `client-config` — klucza nie ma w aplikacji, tak samo jak w SPA.

**307. Cudzą zmianę nakładamy na plan, a nie pobieramy planu od nowa.** Przy premierze zdarzeń
jest dużo; pobieranie całej sali po każdym cudzym kliknięciu to setki żądań i migający ekran.
`seats.changed` niesie stan absolutny wymienionych miejsc i dokładnie to nakładamy (kod z bloku F1,
przetestowany wcześniej niż wpięty). Pełne pobranie zostaje dla dwóch sytuacji: `seats.resync`
od serwera i powrót zerwanego łącza.

**308. Klient MUSI widzieć, kiedy plan sali może być nieaktualny.** Bez tego wybiera miejsca
z obrazka, który zamarzł pięć minut temu w windzie, a odmowę dostaje dopiero przy kliknięciu — i
wygląda to jak błąd aplikacji, nie jak utrata łącza. Znaczek w pasku tytułu mówi o stanie
połączenia, a pasek nad planem pojawia się tylko wtedy, gdy połączenia nie ma, i od razu daje
przycisk odświeżenia. Oba widgety dostają status PARAMETREM, więc testują się bez gniazda.

### Pułapki

**DM. Test, który nie podstawi klienta czasu rzeczywistego, otworzy PRAWDZIWE gniazdo.** Odkąd
ekran wyboru miejsc subskrybuje kanał seansu, każdy test tego ekranu i tego stanu buduje
`realtimeClientProvider` — a ten w wersji produkcyjnej woła `WebSocketChannel.connect`. Test
poszedłby do sieci, w CI wisiałby na timeoucie, a lokalnie zachowywał się różnie w zależności od
tego, czy stoi Reverb. Dlatego oba istniejące pliki testów dostały w tym bloku
`realtimeClientProvider.overrideWithValue(AsyncData(atrapa))`. Sprawdzone w źródle Riverpoda 3.4.3:
`overrideWithValue` na `FutureProvider` przyjmuje `AsyncValue<T>`, więc atrapa wchodzi gotowa,
bez uruchamiania ciała providera.

**DO. Test, który tylko `read`uje providera, NIE odtwarza sytuacji z ekranu.** Pięć testów stanu
kończyło się limitem czasu, a jeden pokazywał wersję planu sprzed zdarzenia — wyglądało to na
przebudowę notifiera albo na zawieszenie w kliencie WebSocketa. Obie hipotezy okazały się
nietrafione; rozstrzygnął dopiero przebieg diagnostyczny, który wypisywał KAŻDE przejście stanu.
Po dołożeniu `container.listen(...)` te same testy zaczęły przechodzić, a wypisane przejścia
pokazały przebieg dokładnie taki, jakiego oczekiwałem: `ładowanie → dane v=7 → dane v=9`, przy
JEDNYM pobraniu planu. Czyli logika była poprawna od początku, a fałszywy był test: ekran
`watch`uje stan wyboru miejsc, więc jest jego obserwatorem, a mój test tylko go czytał.
Zdarzenia przekazywane przez `ref.listen` ze środka notifiera potrzebują obserwatora.
Wniosek na stałe: test stanu ekranowego ZAWSZE zakłada obserwatora — i przy okazji zapisuje
przejścia, bo to one dowodzą, że zdarzenie nałożyło się na plan, zamiast wywołać pobranie
całej sali od nowa. Sprawdziłem też w źródle 3.4.3, że to nie jest automatyczne zwalnianie
providera: `AsyncNotifierProvider` ma `isAutoDispose = false` domyślnie.

**DP. W teście widgetów klienta z timerem trzeba zamknąć W CIELE testu, nie w `addTearDown`.**
Test widgetów sprawdza „brak zaległych timerów” zaraz po ciele testu, ZANIM wykonają się
sprzątania zarejestrowane przez `addTearDown`. Klient czasu rzeczywistego po handshake'u ma
uruchomiony timer ciszy, więc test kończył się asercją `A Timer is still pending even after the
widget tree was disposed` — mimo że sprzątanie było napisane poprawnie, tylko za późno.

**DQ. W strefie testów widgetów nie wolno CZEKAĆ na zamknięcie strumienia.** Poprawka z pułapki DP
(zamknięcie klienta w ciele testu zamiast w `addTearDown`) zamieniła jeden padający test w test
wiszący dziesięć minut: `await dispose()` czeka na zamknięcie strumienia gniazda, a to w strefie
testów widgetów nie wraca bez pompowania klatek. W zwykłym `test()` dokładnie ten sam `await`
działa bez zarzutu — i działa w testach stanu, gdzie stoi w `addTearDown`. Rozwiązanie wynika
z kolejności w samym `dispose`: timery anulują się SYNCHRONICZNIE, na początku metody, więc
wystarczy wywołać ją bez `await` (`unawaited`), żeby sprawdzenie „brak zaległych timerów”
przeszło. Do tego każdy test widgetów, który dotyka klienta czasu rzeczywistego, dostaje własny
limit 30 sekund — żeby następne takie potknięcie kosztowało pół minuty, nie dziesięć.

Trzy pułapki z jednego testu (DP, DQ i wcześniejsza DL) mają wspólny mianownik i warto go
zapamiętać: **strefa testów widgetów ma własny zegar i własną pętlę zdarzeń**. Wszystko, co
czeka na czas albo na strumień, zachowuje się tam inaczej niż w zwykłym teście. Dlatego logikę
czasu rzeczywistego testujemy w `test()`, a w `testWidgets` sprawdzamy wyłącznie to, co widać
na ekranie.

## Blok H1 — kontrakt płatności i stan checkoutu

### Decyzje

**309. Nieznany status rezerwacji to `unknown`, a nie wyjątek.** Tak samo jak przy statusie
miejsca (decyzja 284), ale powód jest tu mocniejszy: to historia ZAKUPÓW klienta. Gdyby kino
dodało szósty status, lista rezerwacji ma się pokazać, a nie wywrócić. W takiej sytuacji
pokazujemy etykietę z serwera i nie pozwalamy na żadną akcję, której nie rozumiemy — bo
`isPending`, `isPaid` i `isClosed` są wtedy wszystkie fałszywe, więc ekran sam z siebie nie
zaproponuje ani płatności, ani biletu, ani anulowania.

**310. Klucz publiczny Stripe'a przychodzi Z SERWERA, nie z parametru buildu.** Gdyby był
wkompilowany, podmiana konta Stripe wymagałaby wydania nowej wersji aplikacji — a wersja ze sklepu
żyje u ludzi miesiącami i nie ma sposobu, żeby wymusić aktualizację. SPA bierze ten klucz z tego
samego miejsca, więc oba klienty są zasilane jednym ustawieniem serwera. Sam `client_secret` nie
jest sekretem konta (tym jest klucz tajny, który nigdy nie opuszcza serwera), ale jest przepustką
do TEJ płatności, więc nie trafia do logów, raportów ani komunikatów o błędach — `toString()`
obu obiektów pokazuje tylko dostawcę i status, i jest na to test.

**311. Status intencji płatności trzymamy jako surowy napis, nie jako wyliczenie.** Lista statusów
Stripe'a jest długa i zmienia się niezależnie od nas. Aplikacja zadaje jej tylko dwa pytania —
„czy trzeba jeszcze zapłacić” (`requires_payment_method`) i „czy poszło” (`succeeded`) — więc
wyliczenie dałoby tu pozorną ścisłość i realne ryzyko wyjątku przy statusie, który nas nie
dotyczy.

**312. Checkout nie wysyła NICZEGO w ciele żądania.** Miejsca wynikają z blokad przypisanych do
sesji zakupowej, a kwota z cennika seansu. Klient nie ma jak wpłynąć na to, ile zapłaci, bo nie
ma czego podać. To jest warte wypowiedzenia na głos na rozmowie: kwota policzona po stronie
klienta i przesłana do serwera to klasyczna dziura, a tutaj nie da się jej zrobić, bo pola
nie istnieją. Test pilnuje dosłownie tego: `expect(sent.single.body, isEmpty)`.

**313. O tym, czy klient zapłacił, rozstrzyga SERWER, a nie wynik z telefonu.** PaymentSheet
potrafi wrócić z sukcesem, zanim webhook Stripe'a dotrze do naszego serwera — a to webhook
oznacza rezerwację jako opłaconą. Aplikacja, która na podstawie własnego wyniku pokaże „kupione”,
będzie czasem kłamać, i to akurat w tę stronę, która boli najbardziej: klient zobaczy bilet,
którego nie ma w bazie. Dlatego po powrocie z płatności pytamy serwer o rezerwację, aż przestanie
być `pending`. Koniec okna odpytywania NIE znaczy „nie zapłacono”, tylko „potwierdzenie jeszcze
nie dotarło” — to dwie różne rzeczy i dostaje o tym osobny komunikat. Okno jest parametrem
(`checkoutPollingProvider`), żeby w testach trwało milisekundy zamiast pół minuty (nauczka
z bloku G1).

**314. Wejście na ekran podsumowania NIE tworzy płatności.** `build()` kontrolera zwraca stan
pusty; intencja w Stripe powstaje dopiero po świadomym kliknięciu. Inaczej samo obejrzenie ekranu
(albo cofnięcie się i wejście ponownie) zostawiałoby w Stripe ślad po nieudanych płatnościach
i — co gorsza — zamieniało koszyk w rezerwację, blokując miejsca komuś, kto by je kupił.
Powrót do przerwanej płatności obsługuje sam serwer: 201 dla nowej, 200 dla powtórzenia na
NIEZMIENIONYM koszyku, 409 z numerem rezerwacji, gdy koszyk się w międzyczasie zmienił.
Wszystkie trzy potwierdzone rozpoznaniem fazy 3 na żywym serwerze.

### Pułapki

**DR. Fikstura nie może WYGLĄDAĆ jak prawdziwy sekret.** Wykonawca paczek skanuje wszystko, co
wychodzi, m.in. wzorcami `pi_…_secret_…` i `pk_live_…`. Fikstura checkoutu z realistycznie
wyglądającym `client_secret` zatrzymałaby cały blok na skanerze — i słusznie, bo skaner nie ma
jak odróżnić sekretu od jego atrapy. Dlatego wartości w fiksturze są celowo NIEPODOBNE do
prawdziwych (`pk_test_fikstura`, `sekret-testowy-fikstura`), a testy sprawdzają tylko to, że pola
są niepuste i że nie wyciekają w `toString()`. Zasada ogólna: fikstura ma mieć kształt prawdziwej
odpowiedzi, a nie jej wygląd.

## Blok H2 — arkusz płatności i kanał rezerwacji

### Decyzje

**315. Stripe siedzi za JEDNĄ ścianą i tylko za nią.** `flutter_stripe` importuje dokładnie jeden
plik aplikacji: `core/payment_sheet.dart`. Powód jest praktyczny, nie estetyczny: `Stripe` to
singleton z polami statycznymi i kanałami do kodu natywnego, więc w `flutter test` nie da się go
wywołać — nie ma platformy. Gdyby stan płatności albo ekran wołały go wprost, ani jednego z nich
nie dałoby się przetestować inaczej niż klikaniem na telefonie, a to jest ostatnie miejsce
w projekcie, w którym chcę polegać na klikaniu. Za interfejsem `PaymentSheet` test podstawia
atrapę i przechodzi całą ścieżkę zakupu, łącznie z odmową karty i rezygnacją, w milisekundach.
Warunki natywne (`FlutterFragmentActivity`, motyw z `Theme.AppCompat`) są spełnione od bloku C.

**316. `completed` z arkusza znaczy „arkusz się domknął”, a nie „zapłacono” — i nazwa w kodzie ma
tego pilnować.** Dlatego wynik nazywa się `PaymentSheetResult.completed`, a nie `paid`: ktoś, kto
będzie to czytał za rok, ma się potknąć o nazwę, zanim napisze `if (result.isPaid) pokażBilet()`.
Po `completed` idziemy prosto do czekania na serwer (decyzja 313). Dwa pozostałe wyniki są
rozdzielone świadomie: **rezygnacja NIE jest błędem** — klient sam zamknął arkusz, wie, że nie
zapłacił, i nie dostaje żadnego komunikatu, a rezerwacja stoi dalej, więc może spróbować ponownie.
Przy odmowie pokazujemy komunikat Stripe'a (`localizedMessage`), bo mówi o rzeczy, której my nie
wiemy — na przykład że to bank odrzucił transakcję. Kod `FailureCode.Canceled` (jedno „l”)
sprawdzony w źródle `stripe_platform_interface` 14, nie zgadnięty.

**317. Zdarzenie z kanału rezerwacji to WYZWALACZ, nie dane.** To odwrotnie niż przy planie sali,
gdzie `seats.changed` niesie stan absolutny miejsc i wolno go nałożyć bez pytania serwera
(decyzja 307). Tutaj po otrzymaniu ramki pytamy REST o rezerwację. Powód: rezerwacja to ZAKUP,
więc to, co widzi klient, ma pochodzić z jednego źródła — tego samego, które pokaże historię
zakupów i bilet. Ramka mówi tylko „coś się zmieniło, spytaj”. Dzięki temu nie ma drugiej ścieżki
budowania stanu zakupu, którą trzeba by testować osobno i która mogłaby się rozjechać z pierwszą.
`occurred_at` z ramki świadomie pomijamy: kolejność rozstrzyga odpowiedź REST-a.

**318. Odpytywanie zostaje drogą PEWNĄ, kanał jest tylko drogą szybką.** Kuszące było zastąpić
odpytywanie kanałem — jedno żądanie mniej i natychmiastowa reakcja. Nie wolno, bo zdarzenie nie
musi dojść: serwer wysyła je przez Reverba za bezpiecznikiem, który przy awarii po prostu nie
wysyła (widać to w `RealtimeNotifier`: metoda zwraca `false` i nikt tego nie ponawia), a zaległych
ramek nikt nie powtarza. Gdyby aplikacja opierała się tylko na kanale, awaria Reverba zamieniłaby
udaną płatność w ekran „czekamy” bez końca. Dlatego pętla odpytywania działa jak wcześniej,
a kanał robi jedną rzecz: odświeża rezerwację, na czym pętla kończy się w następnym obrocie, bez
kolejnego pytania. Test mierzy to dosłownie — dwa żądania zamiast trzech.

### Pułapki

**DS. Kanału rezerwacji nie da się zasubskrybować przed odpowiedzią checkoutu — i serwer na to
liczy.** Nazwa kanału to `private-bookings.{reference}`, a `reference` poznajemy dopiero
z odpowiedzi checkoutu. To nie jest niedogodność do obejścia: serwer świadomie NIE wysyła na ten
kanał zdarzenia o statusie `pending` (komentarz w `RealtimeNotifier` mówi wprost, że nikt nie może
jeszcze słuchać, więc wysyłka byłaby pustym żądaniem HTTP). Wniosek dla aplikacji: subskrypcja
powstaje po `start()`, a pierwsze zdarzenie, na jakie wolno liczyć, to już rozstrzygnięcie.
Osobno warto pamiętać, że to kanał WŁAŚCICIELA: podpis idzie przez `ApiClient`, czyli z tokenem
Sanctum, i `BookingPolicy::listen` odmówi każdemu innemu — więc kupujący bez konta nie zobaczy
tu niczego, w przeciwieństwie do kanału planu sali.

## Blok H3 — ekran podsumowania i płatności

### Decyzje

**319. Ekran płatności bierze SAM KOSZYK, a nie stan wyboru miejsc.** Najprościej byłoby sięgnąć
po `seatSelectionProvider` — jest już gotowy i ma w sobie koszyk. Kosztowałoby to jednak pobranie
całego planu sali (w dużej sali kilkaset foteli z cenami i kategoriami) oraz drugą subskrypcję
kanału seansu, a na ekranie podsumowania nie ma po tym ani jednego śladu. Dlatego doszedł
`cartProvider(screeningId)`: jedno żądanie po miejsca i kwotę. Przy okazji test pilnuje tego
wprost — `expect(api.seatMapCalls, 0)`.

**320. Przy konflikcie 409 przycisku „Przejdź do płatności” po prostu NIE MA.** Serwer odpowiedział,
że płatność za te miejsca jest już rozpoczęta; gdyby klient kliknął ponownie, dostałby znów 409.
Zamiast tego pokazujemy numer tamtej rezerwacji i zostawiamy jedno wyjście: „Zrezygnuj z płatności”.
To jest ta sama zasada, co przy fotelach zajętych przez kogoś innego — element, który nie może się
udać, nie ma być klikalny (decyzja 291). Ekran nie zgaduje przy tym, w jakim stanie jest tamta
rezerwacja: pobiera ją przez REST i pokazuje etykietę z serwera, więc jeśli okaże się już opłacona,
klient zobaczy „Opłacona”, a nie ofertę zapłacenia drugi raz.

**321. Licznik okna płatności dochodzący do zera POBIERA rezerwację.** Sam licznik zatrzymany na
0:00 zostawiłby na ekranie przycisk „Zapłać” do płatności, której serwer już nie przyjmie —
a arkusz Stripe'a otwarty na wygasłej intencji to najgorszy możliwy moment na komunikat o błędzie.
Dlatego `onExpired` woła `refreshBooking()`, a ekran pokazuje to, co naprawdę jest:
„Wygasła” z etykietą serwera i przycisk powrotu do wyboru miejsc. Same sekundy liczymy od
`expires_in_seconds` Z SERWERA, nie z różnicy dat na telefonie (decyzja 288).

**322. Jedna akcja — jeden przycisk, nawet gdy pasują dwa miejsca.** Gdy na seansie jest rozpoczęta
płatność, plan sali jest zamrożony i pasek nad nim mówi o tym wprost — tam doszedł przycisk „Wróć
do płatności”. Wtedy pasek koszyka SWOJEGO przycisku nie pokazuje, choć technicznie mógłby
(powtórzony checkout na niezmienionym koszyku oddaje 200 z tą samą rezerwacją). Dwa przyciski do
tej samej rzeczy w dwóch miejscach jednego ekranu to gotowy sposób na kliknięcie nie w to, co się
chciało — a tu kliknięcie kosztuje pieniądze. Przy pustym koszyku nie ma żadnego z nich: przycisk
prowadzący do ekranu z komunikatem „koszyk jest pusty” jest gorszy niż brak przycisku.

### Pułapki

**DT. `ref.listen` warunkowo — ale warunek na WIDGECIE, nie na wywołaniu.** Kanał rezerwacji da się
zasubskrybować tylko wtedy, gdy znamy numer (pułapka DS), więc nasłuch jest z natury warunkowy.
Wsadzenie `if (reference != null) ref.listen(...)` do `build` ekranu byłoby proszeniem się o kłopot:
`ref.listen` w `build` rejestruje nasłuch na nowo przy każdej przebudowie i lepiej, żeby robił to
bezwarunkowo. Dlatego nasłuch siedzi w osobnym maleńkim widgecie (`_BookingChannel`), a warunkowe
jest samo jego zamontowanie. Efekt jest ten sam, a `ref.listen` ma w swoim `build` dokładnie jedną
drogę wykonania.

**DU. Pola OPCJONALNE w kontrakcie wychodzą dopiero w widgecie — i dobrze, że w analizie.** Nazwa
kategorii miejsca (`category.name`) jest w API opcjonalna, bo pochodzi ze słownika w panelu
administracyjnym i może być pusta. W modelu jest więc `String?`, a ja wstawiłem ją wprost do
`Text(...)`, który wymaga napisu. Pierwszy przebieg bloku stanął na tym po dziewięciu sekundach:
`flutter analyze --fatal-infos` z trybami `strict-casts`, `strict-inference`
i `strict-raw-types` (decyzja 260) zgłosił jeden błąd i wykonawca nie poszedł dalej. Warto
zapamiętać dwie rzeczy. Pierwsza: to nie jest przypadek, że złapała to analiza, a nie telefon —
bez trybów ścisłych `String?` przeszłoby jako `dynamic` i skończyłoby się wyjątkiem w widgecie
u konkretnego klienta, którego miejsce nie ma kategorii. Druga: w pętli po elementach nie da się
przypisać wartości do zmiennej, więc taki wiersz wyciągam do osobnego maleńkiego widgetu —
tam `final String? category = ...` załatwia sprawę bez żadnego `!`.

**DV. W testach widgetów TEKST JEST SZERSZY niż na telefonie — i dzięki temu wyszła prawdziwa
wpadka układu.** Po dołożeniu przycisku „Do płatności” pasek koszyka zaczął wychodzić za ekran
(`A RenderFlex overflowed by 114 pixels on the right`) i padło od razu pięć testów planu sali —
wszystkie te, w których koszyk NIE jest pusty, czyli te, w których widać cenę, licznik i przyciski.
Wygląda to na kaprys testu, ale nie jest: w testach widgetów Flutter używa własnej czcionki, w której
każdy znak jest kwadratem o wysokości stopnia pisma, więc `1 × miejsce · 25,30 zł` zajmuje tam
ponad 350 pikseli zamiast około 150. Test pokazał więc to, co zobaczyłby użytkownik wąskiego
telefonu z większą czcionką systemową — a taki użytkownik istnieje. Poprawka jest w UKŁADZIE,
nie w teście: informacje zostają w jednym wierszu (z `Expanded` na cenie), a przyciski schodzą
do drugiego. Wniosek na stałe: „u mnie się mieści” nie jest argumentem, a overflow w teście
widgetów traktuję jako błąd aplikacji, dopóki nie dowiodę, że to wina samego pomiaru.

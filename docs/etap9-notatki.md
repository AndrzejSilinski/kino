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

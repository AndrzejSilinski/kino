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

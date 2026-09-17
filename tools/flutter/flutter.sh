#!/bin/sh
# Flutter i Android SDK w przypiętym kontenerze (Etap 9, blok A). W WSL nie ma Fluttera ani Darta.
#
# Uruchomienie z katalogu głównego repozytorium:
#   sh tools/flutter/flutter.sh --przygotuj                 obraz + wolumeny (jednorazowo, kilka minut)
#   sh tools/flutter/flutter.sh --version                   wersja SDK
#   sh tools/flutter/flutter.sh create --org pl.silinski --project-name cinema --platforms android mobile
#   sh tools/flutter/flutter.sh pub get --enforce-lockfile  zależności DOKŁADNIE z pubspec.lock (zasada 31)
#   sh tools/flutter/flutter.sh analyze --fatal-infos        analiza statyczna
#   sh tools/flutter/flutter.sh test                         testy jednostkowe i widgetów
#   sh tools/flutter/flutter.sh build apk --debug --dart-define=API_BASE_URL=http://localhost:8080
#   sh tools/flutter/flutter.sh dart pub deps                dart zamiast flutter (pierwszy argument)
#   sh tools/flutter/flutter.sh --powloka                    powłoka w kontenerze (diagnostyka)
#
# Katalogiem roboczym jest mobile/, dopóki istnieje; przed blokiem B — katalog główny repozytorium,
# żeby "flutter create ... mobile" mogło ten katalog założyć.
#
# Dlaczego tak, a nie instalacja w WSL (decyzja 252):
#   - wersje SDK są przypięte po digeście i identyczne u mnie, w wykonawcy paczek i w CI (Etap 10),
#   - --user: pliki projektu należą do użytkownika WSL, nie do roota ani do uid 1001 (zasada 6),
#   - wolumen cinema_flutter_sdk trzyma SDK ZAPISYWALNE dla naszego uid (pułapka CH),
#   - wolumen cinema_flutter_home trzyma HOME kontenera: pamięć podręczną pub i Gradle
#     oraz ~/.android ze STAŁYM kluczem debug — inaczej każdy świeży kontener podpisywałby APK
#     innym kluczem i "adb install -r" odrzucałby aktualizację (decyzja 254).
# Android SDK zostaje w obrazie tylko do odczytu; build debug APK tego nie potrzebuje.
set -eu

FLUTTER_IMAGE='cinema/flutter:3.47.4'
SDK_VOLUME='cinema_flutter_sdk'
HOME_VOLUME='cinema_flutter_home'
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
UIDGID="$(id -u):$(id -g)"

przygotuj() {
    if ! docker image inspect "$FLUTTER_IMAGE" > /dev/null 2>&1; then
        echo "flutter.sh: buduję $FLUTTER_IMAGE z docker/flutter/Dockerfile" >&2
        docker build -t "$FLUTTER_IMAGE" "$ROOT/docker/flutter" >&2
    fi
    if ! docker volume inspect "$SDK_VOLUME" > /dev/null 2>&1; then
        echo "flutter.sh: zasiewam $SDK_VOLUME zawartością SDK z obrazu (ok. 2 GB, kilka minut)" >&2
        docker volume create "$SDK_VOLUME" > /dev/null
        # Pusty wolumen podmontowany pod istniejący katalog obrazu jest zasiewany jego zawartością.
        # Właściciela zmieniamy raz, jednorazowym kontenerem roota — do repozytorium nic nie trafia.
        docker run --rm --user 0:0 -v "$SDK_VOLUME":/home/flutter/sdks/flutter \
            --entrypoint sh "$FLUTTER_IMAGE" -c "chown -R $UIDGID /home/flutter/sdks/flutter" >&2
    fi
    if ! docker volume inspect "$HOME_VOLUME" > /dev/null 2>&1; then
        echo "flutter.sh: zakładam $HOME_VOLUME (HOME kontenera: pub, Gradle, klucz debug)" >&2
        docker volume create "$HOME_VOLUME" > /dev/null
        docker run --rm --user 0:0 -v "$HOME_VOLUME":/home/fh \
            --entrypoint sh "$FLUTTER_IMAGE" -c "chown $UIDGID /home/fh" >&2
    fi
}

NARZEDZIE=flutter
INTERAKCJA=''

case "${1:---version}" in
    --przygotuj)
        przygotuj
        echo "flutter.sh: gotowe (obraz $FLUTTER_IMAGE, wolumeny $SDK_VOLUME i $HOME_VOLUME)"
        exit 0
        ;;
    --powloka)
        shift
        NARZEDZIE=sh
        # -it tylko przy terminalu: w wykonawcy paczek i w teście dymnym stdin nie jest TTY,
        # a docker odmawia wtedy uruchomienia ("the input device is not a TTY").
        # Osobne "if", nie "[ -t 0 ] && ...": przy set -e nieudany test przerwałby skrypt.
        if [ -t 0 ]; then INTERAKCJA='-it'; fi
        ;;
    dart)
        shift
        NARZEDZIE=dart
        ;;
esac

przygotuj

[ "$#" -gt 0 ] || set -- --version

KATALOG=/work
[ -d "$ROOT/mobile" ] && KATALOG=/work/mobile

# GIT_CONFIG_*: SDK jest repozytorium git; przy niezgodności właściciela git odmawia odczytu,
# a wtedy "flutter --version" pokazuje 0.0.0-unknown.
# shellcheck disable=SC2086
exec docker run --rm --user "$UIDGID" $INTERAKCJA \
    -e HOME=/home/fh \
    -e PUB_CACHE=/home/fh/.pub-cache \
    -e GRADLE_USER_HOME=/home/fh/.gradle \
    -e ANDROID_SDK_ROOT=/home/flutter/sdks/android-sdk \
    -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
    -e CI=true -e FLUTTER_SUPPRESS_ANALYTICS=true \
    -v "$SDK_VOLUME":/home/flutter/sdks/flutter \
    -v "$HOME_VOLUME":/home/fh \
    -v "$ROOT":/work \
    -w "$KATALOG" \
    --entrypoint "$NARZEDZIE" "$FLUTTER_IMAGE" "$@"

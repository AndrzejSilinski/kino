# Etap 9, blok A: obraz z Flutterem i Android SDK — analiza, testy i build APK bez instalowania
# czegokolwiek w WSL (tak samo jak Node dla frontu w tools/frontend/npm.sh).
#
# Baza przypięta po digeście (zasada 31, pułapka W):
#   gmeligio/flutter-android:3.47.4 = Flutter 3.47.4, Dart 3.13.3, Android SDK 36
#   (build-tools 36.0.0, licencje zaakceptowane), JDK 17, NDK 28.2.13676358, linux/amd64.
# Dlaczego nie cirruslabs/flutter, używany wcześniej w wielu projektach: to repozytorium
# przestało wydawać obrazy w maju 2026 i nie ma wydania 3.47 (ostatnie to 3.44.0).
#
# Jedyna zmiana wobec bazy: użytkownik obrazu (flutter, uid 1001) ma katalog domowy z prawami
# 700, a całe SDK leży w jego środku. Kontenery uruchamiamy jako uid użytkownika WSL (zasada 6),
# więc bez tej zmiany każde wywołanie kończy się "flutter: Permission denied" (pułapka CG).
# Zmieniamy TYLKO prawa tych katalogów i plików, które nie miały prawa odczytu dla innych,
# czyli warstwę metadanych rzędu 50 KB — a nie kopię 3 GB SDK.
#
# Prawo ZAPISU do SDK daje osobny wolumen (tools/flutter/flutter.sh), nie ten obraz:
# Gradle zakłada katalogi .gradle/ i build/ WEWNĄTRZ $FLUTTER_ROOT/packages/flutter_tools/gradle,
# bo szablon Fluttera dołącza tę budowę przez includeBuild, a GRADLE_USER_HOME tego nie
# przenosi (pułapka CH).
FROM gmeligio/flutter-android@sha256:ae5cb8df28e0ff40a51e683c1a5090b579cca055749dcc9fa405f040c8085557

# Etap 10, blok G (decyzja 271 domknięta): komponenty SDK, które Gradle dociągał w trakcie każdego
# pierwszego buildu — firebase_core i inne wtyczki kompilują się wobec starszych platform niż
# android-36 z bazy, a cmake jest potrzebny do natywnej części buildu. Lista wzięta z wolumenu
# po prawdziwym buildzie (docs/etap10-notatki.md, blok F1), nie z dokumentacji. W obrazie, bo CI
# zaczyna od pustego wolumenu i pobierałby je przy każdym przebiegu. Licencje zaakceptowane w bazie.
RUN sdkmanager --install "platforms;android-34" "platforms;android-35" "cmake;3.22.1" > /dev/null \
 && test -d "$ANDROID_HOME/platforms/android-34" \
 && test -d "$ANDROID_HOME/platforms/android-35" \
 && test -x "$ANDROID_HOME/cmake/3.22.1/bin/cmake"

USER root
RUN chmod 0755 /home/flutter \
 && find /home/flutter/sdks -type d ! -perm -o=rx -exec chmod o+rx {} + \
 && find /home/flutter/sdks -type f ! -perm -o=r -exec chmod o+r {} +
USER flutter

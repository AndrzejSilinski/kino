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

USER root
RUN chmod 0755 /home/flutter \
 && find /home/flutter/sdks -type d ! -perm -o=rx -exec chmod o+rx {} + \
 && find /home/flutter/sdks -type f ! -perm -o=r -exec chmod o+r {} +
USER flutter

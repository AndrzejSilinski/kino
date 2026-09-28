# Reguły ProGuard/R8 — przygotowanie pod build release w Etapie 10.
#
# W Etapie 9 budujemy tylko debug APK, w którym minifikacja jest wyłączona, więc
# ten plik na nic nie wpływa. Powstaje teraz, żeby przy pierwszym release nie
# szukać po forach, dlaczego arkusz płatności wysypuje się dopiero na produkcji:
# R8 usuwa klasy używane refleksyjnie, a SDK Stripe'a i wtyczki Firebase z nich
# korzystają.

# Stripe: klasy SDK i mostka są wołane refleksyjnie z warstwy natywnej.
-keep class com.stripe.android.** { *; }
-dontwarn com.stripe.android.**
-keep class com.stripe.android.pushProvisioning.** { *; }
-dontwarn com.stripe.android.pushProvisioning.**

# Firebase Cloud Messaging: usługa i odbiorniki wskazywane z manifestu.
-keep class com.google.firebase.** { *; }
-dontwarn com.google.firebase.**

# Flutter: punkty wejścia oznaczone adnotacją @pragma('vm:entry-point'),
# w tym handler powiadomień w tle (blok M), są wołane z silnika.
-keep class io.flutter.embedding.** { *; }
-keep @io.flutter.embedding.engine.plugins.FlutterPlugin class * { *; }

# Reguły R8 dla wydania (Etap 9 przygotował, Etap 10 blok G włączył minifikację).
#
# R8 usuwa klasy używane refleksyjnie, a SDK Stripe'a i wtyczki Firebase z nich
# korzystają — bez tych reguł arkusz płatności wysypuje się dopiero w wydaniu.
# Biblioteki dokładają też własne reguły (consumer rules): stripe_android 14.1.0
# trzyma cały com.stripe.**, Gson — swoje (flutter_local_notifications od v19),
# Flutter — flutter_proguard_rules.pro z SDK. Reguły niżej są celowo szerokie:
# mniejszy zysk z R8 w zamian za brak awarii, której nie złapie żaden test
# w kontenerze (decyzja 426).

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

# Flutter: komponenty odroczone (Play Core) — osadzenie odwołuje się do nich,
# a aplikacja ich nie używa i biblioteki nie dołącza. Bez tego R8 może przerwać
# build na "Missing class com.google.android.play.core...".
-dontwarn com.google.android.play.core.**

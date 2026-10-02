// Etap 10, blok G: import, a nie pełna nazwa java.util.Properties — w skrypcie Gradle "java" to
// akcesor rozszerzenia projektu (JavaPluginExtension) i przesłania pakiet (pułapka FD).
import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Etap 9, decyzja 292: google-services.json jest poza repozytorium, więc wtyczkę
// Google Services stosujemy TYLKO wtedy, gdy plik istnieje. Bez niego build
// przechodzi (przydatne w CI i przy pracy nad ekranami bez pusha), a aplikacja
// po prostu nie rejestruje urządzenia FCM.
if (file("google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
} else {
    // logger.quiet, nie lifecycle/warn: "flutter build" bez -v uruchamia Gradle z -q, które
    // wycina wszystko poza QUIET i ERROR (gradle.dart na tagu 3.47.4) — pułapka FC.
    logger.quiet(
        "Etap 9: brak android/app/google-services.json - wtyczka Google Services " +
            "pominieta, push FCM nie bedzie dzialac w tym APK."
    )
}

// Etap 10, blok G, decyzja 425: podpis wydania. Magazyn kluczy i hasło NIGDY w repozytorium —
// leżą w katalogu poza nim (domyślnie ~/.kino-podpis, zakłada go tools/mobile/podpis.sh nowy),
// który tools/flutter/flutter.sh montuje tylko przy "build" i podaje tu przez KINO_PODPIS.
// Klucze pliku podpis.properties: magazyn (ścieżka względem pliku), alias, haslo — jedno hasło,
// bo magazyn PKCS12 nie obsługuje osobnego hasła klucza. Plik wskazany, ale niekompletny = błąd
// konfiguracji (nigdy ciche przejście na klucz debug); brak KINO_PODPIS = klucz debug
// z ostrzeżeniem (CI bez sekretów, próby lokalne).
val podpisPlik: File? = System.getenv("KINO_PODPIS")?.takeIf { it.isNotBlank() }?.let { File(it).absoluteFile }
val podpis: Properties? = podpisPlik?.let { plik ->
    if (!plik.isFile) {
        throw GradleException("Etap 10: KINO_PODPIS wskazuje na $plik, a takiego pliku nie ma")
    }
    Properties().apply { plik.inputStream().use { load(it) } }
}

fun podpisWartosc(klucz: String): String =
    podpis?.getProperty(klucz)?.trim()?.takeIf { it.isNotEmpty() }
        ?: throw GradleException("Etap 10: w $podpisPlik brak wartosci klucza \"$klucz\"")

val budujeWydanie = gradle.startParameter.taskNames.any { it.contains("Release") }

android {
    namespace = "pl.silinski.cinema"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        // Wymaganie flutter_local_notifications: ta biblioteka używa API
        // java.time, którego nie ma na starszych Androidach, więc kompilacja
        // musi mieć włączone desugarowanie bibliotek standardowych.
        isCoreLibraryDesugaringEnabled = true
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "pl.silinski.cinema"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (podpis != null) {
            create("wydanie") {
                val magazyn = podpisPlik!!.parentFile.resolve(podpisWartosc("magazyn"))
                if (!magazyn.isFile) {
                    throw GradleException("Etap 10: brak magazynu kluczy $magazyn (klucz \"magazyn\" w $podpisPlik)")
                }
                storeFile = magazyn
                storePassword = podpisWartosc("haslo")
                keyAlias = podpisWartosc("alias")
                keyPassword = podpisWartosc("haslo")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (podpis != null) {
                signingConfigs.getByName("wydanie")
            } else {
                if (budujeWydanie) {
                    logger.quiet(
                        "Etap 10: UWAGA - brak KINO_PODPIS, APK wydania podpisany kluczem DEBUG " +
                            "(do prob, nie do dystrybucji). Klucz: tools/mobile/podpis.sh nowy."
                    )
                }
                signingConfigs.getByName("debug")
            }
            // R8 (zmniejszenie i zaciemnienie kodu Javy/Kotlina) i usuwanie nieużywanych zasobów.
            // Wtyczka Gradle Fluttera włącza oba sama (FlutterPlugin.kt na tagu 3.47.4, o ile nie
            // podano --no-shrink); zapisane jawnie, żeby wydanie nie zależało od domyślnych
            // ustawień narzędzia. Zasoby wołane po nazwie chroni res/raw/keep.xml.
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }
}

dependencies {
    // Wersja wymagana przez flutter_local_notifications (jej README).
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}

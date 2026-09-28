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
    logger.lifecycle(
        "Etap 9: brak android/app/google-services.json - wtyczka Google Services " +
            "pominieta, push FCM nie bedzie dzialac w tym APK."
    )
}

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

    buildTypes {
        release {
            // Etap 9 buduje tylko debug APK. Podpis release i minifikacja
            // wchodzą w Etapie 10 razem z CI; reguły dla Stripe i Fluttera
            // leżą już w proguard-rules.pro, żeby ten krok był gotowy.
            signingConfig = signingConfigs.getByName("debug")
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

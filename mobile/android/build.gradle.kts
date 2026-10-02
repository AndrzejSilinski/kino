allprojects {
    repositories {
        google()
        mavenCentral()
    }
    // Etap 10, blok G (pułapka FF): stripe_android 14.1.0 deklaruje compileOnly
    // stripe-android-issuing-push-provisioning, a ta biblioteka ciągnie
    // play-services-tapandpay:18.8.0 — SDK Google'a, którego NIE MA w publicznych
    // repozytoriach (dostęp tylko po umowie z Google). Kompilacja go nie potrzebuje, ale
    // zadanie lintVitalAnalyzeRelease rozwiązuje tę zależność i build wydania pada.
    // Aplikacja nie używa wydawania kart do portfela (push provisioning), a biblioteka
    // i tak nie trafia do APK (compileOnly) — wykluczamy ją ze wszystkich konfiguracji
    // zamiast wyłączać lint wydania.
    configurations.configureEach {
        exclude(group = "com.google.android.gms", module = "play-services-tapandpay")
    }
}

val newBuildDir: Directory =
    rootProject.layout.buildDirectory
        .dir("../../build")
        .get()
rootProject.layout.buildDirectory.value(newBuildDir)

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}
subprojects {
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}

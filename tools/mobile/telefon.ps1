# Telefon z Androidem przez adb (Etap 9, blok A). Uruchamiane w PowerShellu na Windowsie,
# NIE w WSL: port USB widzi Windows, a nie maszyna WSL.
#
# Plik zaczyna sie od znacznika BOM (EF BB BF) i to jest WYMAGANE, a nie przypadek:
# Windows PowerShell 5.1 - domyslny na Windowsie - czyta pliki .ps1 jako ANSI, jesli
# nie znajdzie BOM-u. Polskie znaki i myslnik w komunikatach rozsypuja sie wtedy tak,
# ze skrypt nie przechodzi nawet parsowania (Etap 9, blok O, pulapka EC).
#
# Wymaga tylko Android SDK Platform-Tools (sam adb, kilka MB z dl.google.com) — bez Android
# Studio i bez emulatora (decyzja 255). Domyślnie szuka adb w %USERPROFILE%\platform-tools.
#
# Po co "reverse" (decyzja 256): "adb reverse tcp:8080 tcp:8080" przekierowuje port 8080
# z telefonu na port 8080 komputera, więc aplikacja na telefonie widzi http://localhost:8080
# dokładnie tak, jak przeglądarka w Windows. Dzięki temu API, WebSocket i adresy absolutne
# z APP_URL (plakaty, avatar, qr_url) działają bez zmiany kontraktu API i bez otwierania
# portów w zaporze. Przekierowanie trzeba powtórzyć po odłączeniu kabla i po restarcie telefonu.
#
# Przykłady:
#   powershell -ExecutionPolicy Bypass -File tools\mobile\telefon.ps1 stan
#   powershell -ExecutionPolicy Bypass -File tools\mobile\telefon.ps1 reverse
#   powershell -ExecutionPolicy Bypass -File tools\mobile\telefon.ps1 instaluj -Apk $env:USERPROFILE\Desktop\etap9_C_app-debug.apk
#   powershell -ExecutionPolicy Bypass -File tools\mobile\telefon.ps1 logi        (Ctrl+C kończy zapis)
#   powershell -ExecutionPolicy Bypass -File tools\mobile\telefon.ps1 wszystko -Apk ...\etap9_C_app-debug.apk

param(
    [Parameter(Position = 0)]
    [ValidateSet('stan', 'reverse', 'instaluj', 'logi', 'wszystko')]
    [string]$Akcja = 'stan',

    [string]$Apk = "$env:USERPROFILE\Desktop\etap9_app-debug.apk",
    [string]$Adb = "$env:USERPROFILE\platform-tools\adb.exe",
    [string]$Log = "$env:USERPROFILE\Desktop\etap9_logcat.txt",
    [int]$Port = 8080,
    [string]$Pakiet = 'pl.silinski.cinema'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $Adb)) {
    Write-Error "Nie znaleziono adb: $Adb. Rozpakuj Android SDK Platform-Tools do %USERPROFILE%\platform-tools albo podaj -Adb."
}

function Stan {
    Write-Host '== Podłączone urządzenia (authorized = zgoda na debugowanie wydana):'
    & $Adb devices -l
    Write-Host '== Wersja Androida i model:'
    & $Adb shell getprop ro.build.version.release
    & $Adb shell getprop ro.product.model
    Write-Host '== Usługi Google Play (wymagane dla FCM; pusto = brak):'
    & $Adb shell pm list packages com.google.android.gms
    Write-Host '== Aktywne przekierowania portów:'
    & $Adb reverse --list
}

function Reverse {
    & $Adb reverse "tcp:$Port" "tcp:$Port"
    Write-Host "== Przekierowanie ustawione, sprawdzam z telefonu (oczekiwane HTTP/1.1 200):"
    # /up to endpoint zdrowia Laravela; odpowiedź 200 oznacza, że telefon dosięga nginx w Dockerze.
    & $Adb shell "curl -s -o /dev/null -w '%{http_code}' http://localhost:$Port/up" 2>$null
    Write-Host ''
    & $Adb reverse --list
}

function Instaluj {
    if (-not (Test-Path $Apk)) { Write-Error "Nie znaleziono APK: $Apk" }
    Write-Host "== Instaluję $Apk (suma SHA256 poniżej — porównaj z raportem paczki):"
    (Get-FileHash -Algorithm SHA256 $Apk).Hash.ToLower()
    # -r: aktualizacja z zachowaniem danych aplikacji (tokenu w bezpiecznym magazynie).
    # Błąd INSTALL_FAILED_UPDATE_INCOMPATIBLE = APK podpisany innym kluczem debug:
    # wolumen cinema_flutter_home został usunięty, więc klucz jest nowy. Wtedy:
    #   adb uninstall pl.silinski.cinema, potem instalacja jeszcze raz.
    & $Adb install -r $Apk
}

function Logi {
    Write-Host "== Czyszczę bufor i piszę logi do $Log (Ctrl+C kończy)"
    & $Adb logcat -c
    # flutter: komunikaty z print/debugPrint; pozostałe tagi przydają się przy FCM i Stripe.
    & $Adb logcat -v time | Tee-Object -FilePath $Log
}

switch ($Akcja) {
    'stan'     { Stan }
    'reverse'  { Reverse }
    'instaluj' { Instaluj }
    'logi'     { Logi }
    'wszystko' { Stan; Reverse; Instaluj; Write-Host "== Aplikacja: $Pakiet. Logi: telefon.ps1 logi" }
}

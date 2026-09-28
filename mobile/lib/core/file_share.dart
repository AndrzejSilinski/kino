// Podanie pliku systemowi — za jedną ścianą, tak samo jak arkusz płatności.
//
// Decyzja 327: ta sama zasada co przy Stripe (decyzja 315). `share_plus`
// i `path_provider` rozmawiają z kodem natywnym przez kanały platformy, więc
// w `flutter test` ich nie ma. Gdyby ekran biletu wołał je wprost, testu tego
// ekranu nie dałoby się napisać — a to ekran, na którym klient szuka biletu
// dziesięć minut przed seansem.
//
// „Udostępnij”, a nie „zapisz w Pobranych”, i to jest wybór, nie wygoda:
// systemowe okno udostępniania pozwala JEDNYM ruchem zapisać plik na dysku,
// wysłać go mailem albo wrzucić do portfela — a zapis do wspólnego katalogu
// wymagałby na Androidzie 13+ osobnych uprawnień i tak czy tak kończyłby się
// pytaniem, czego użytkownik chce z tym plikiem zrobić.

import 'dart:io';
import 'dart:typed_data';

import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

abstract interface class FileShare {
  Future<void> shareBytes({
    required String filename,
    required List<int> bytes,
    required String mime,
  });
}

class SystemFileShare implements FileShare {
  const SystemFileShare();

  @override
  Future<void> shareBytes({
    required String filename,
    required List<int> bytes,
    required String mime,
  }) async {
    // Plik ląduje w katalogu TYMCZASOWYM aplikacji, nie w publicznym.
    // Bilet jest przepustką na salę, więc nie ma powodu, żeby zostawał
    // w miejscu, do którego zagląda każda inna aplikacja na telefonie.
    // Kopię trwałą robi użytkownik, świadomie, przez okno udostępniania.
    final Directory dir = await getTemporaryDirectory();
    final File file = File('${dir.path}/$filename');
    await file.writeAsBytes(Uint8List.fromList(bytes), flush: true);
    await SharePlus.instance.share(
      ShareParams(files: <XFile>[XFile(file.path, mimeType: mime)]),
    );
  }
}

// Zdjęcie z aparatu albo z galerii — za jedną ścianą, jak Stripe i udostępnianie.
//
// Decyzja 335: `image_picker` i `flutter_image_compress` rozmawiają z kodem
// natywnym, więc w `flutter test` ich nie ma. Za interfejsem `PhotoPicker`
// ekran konta daje się przetestować do końca, razem z odmową uprawnienia.
//
// Decyzja 336: NIE deklarujemy uprawnienia CAMERA w manifeście — i to jest
// wybór, nie przeoczenie. `image_picker` robi zdjęcie przez systemową
// aplikację aparatu (`ACTION_IMAGE_CAPTURE`), która ma własne uprawnienia.
// Gdybyśmy zadeklarowali CAMERA, Android zacząłby wymagać zgody w czasie
// działania na coś, czego nasza aplikacja i tak nie robi — a każde zbędne
// uprawnienie to pytanie, na które użytkownik może odpowiedzieć „nie", i pozycja
// na liście uprawnień w sklepie. Galerię na Androidzie 13+ obsługuje systemowy
// wybierak zdjęć, też bez uprawnienia. Gałąź `denied` zostaje jako obrona:
// systemowy komponent może odmówić z powodów, których nie kontrolujemy.
//
// Decyzja 337: zdjęcie zmniejszamy DO WYSŁANIA, nie dla oszczędności.
// Serwer przyjmuje najwyżej 5 MB i najwyżej 16 Mpx, a zdjęcie z dzisiejszego
// telefonu ma 12 Mpx i regularnie przekracza 5 MB — bez zmniejszenia avatar
// nie dałby się wgrać właśnie tym osobom, które mają najlepsze aparaty.
// Przy okazji dwie rzeczy: wynik jest ZAWSZE w JPEG-u, więc nazwa pliku
// `avatar.jpg`, po której serwer poznaje rozszerzenie, zawsze zgadza się
// z zawartością (decyzja 333); i wynik jest BEZ EXIF-u, więc współrzędne GPS
// miejsca, w którym zrobiono zdjęcie, nie opuszczają telefonu.

// Bez `dart:typed_data`: `Uint8List` re-eksportuje `flutter/services.dart`,
// którego i tak potrzebujemy dla `PlatformException` (pułapka DY).
import 'package:flutter/services.dart';
import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:image_picker/image_picker.dart';

enum PhotoOrigin { camera, gallery }

enum PhotoResult {
  /// Zdjęcie gotowe do wysłania.
  picked,

  /// Użytkownik zamknął aparat albo wybierak — to nie błąd.
  cancelled,

  /// System odmówił dostępu; sami o niego nie pytamy (decyzja 336).
  denied,

  /// Cokolwiek innego: brak aparatu, nieudane przetworzenie pliku.
  failed,
}

class PickedPhoto {
  const PickedPhoto({required this.bytes, this.filename = 'avatar.jpg'});

  final List<int> bytes;
  final String filename;

  int get kilobytes => (bytes.length / 1024).ceil();
}

class PhotoOutcome {
  const PhotoOutcome(this.result, {this.photo});

  final PhotoResult result;
  final PickedPhoto? photo;

  @override
  String toString() => 'PhotoOutcome(${result.name})';
}

abstract interface class PhotoPicker {
  Future<PhotoOutcome> take(PhotoOrigin origin);
}

class SystemPhotoPicker implements PhotoPicker {
  const SystemPhotoPicker();

  /// Bok, do którego zmniejszamy zdjęcie.
  ///
  /// `minWidth`/`minHeight` w tej paczce działają jak GÓRNE ograniczenie
  /// z zachowaniem proporcji i nigdy nie powiększają — sprawdzone w źródle,
  /// bo nazwa sugeruje coś przeciwnego. Dla zdjęcia 4032×3024 wychodzi
  /// 683×512: krótszy bok trafia w 512, czyli grubo nad wymaganym przez
  /// serwer minimum 128, a plik schodzi do kilkudziesięciu kilobajtów.
  /// Serwer i tak skadruje do kwadratu 256×256, więc więcej nie ma sensu.
  static const int side = 512;

  @override
  Future<PhotoOutcome> take(PhotoOrigin origin) async {
    try {
      final XFile? file = await ImagePicker().pickImage(
        source: origin == PhotoOrigin.camera
            ? ImageSource.camera
            : ImageSource.gallery,
        // Pełnych metadanych nie potrzebujemy — potrzebujemy pikseli.
        requestFullMetadata: false,
      );
      if (file == null) {
        // Puste zamknięcie aparatu to `null`, nie wyjątek (sprawdzone
        // w kodzie wtyczki). Rezygnacja nie jest awarią.
        return const PhotoOutcome(PhotoResult.cancelled);
      }
      final Uint8List jpeg = await FlutterImageCompress.compressWithList(
        await file.readAsBytes(),
        minWidth: side,
        minHeight: side,
        quality: 85,
        format: CompressFormat.jpeg,
      );
      // Ta metoda nie zwraca null — przy niepowodzeniu oddaje PUSTĄ listę.
      if (jpeg.isEmpty) {
        return const PhotoOutcome(PhotoResult.failed);
      }
      return PhotoOutcome(PhotoResult.picked, photo: PickedPhoto(bytes: jpeg));
    } on PlatformException catch (error) {
      // Kody są po stronie natywnej i różnią się między systemami
      // (`camera_access_denied`, `photo_access_restricted`, …), więc
      // rozpoznajemy je po fragmencie nazwy, a nie po pełnej liście.
      final String code = error.code.toLowerCase();
      final bool denied =
          code.contains('denied') || code.contains('restricted');
      return PhotoOutcome(denied ? PhotoResult.denied : PhotoResult.failed);
    } on Exception catch (_) {
      return const PhotoOutcome(PhotoResult.failed);
    }
  }
}

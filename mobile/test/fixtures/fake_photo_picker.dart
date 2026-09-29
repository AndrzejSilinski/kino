// Atrapa aparatu i galerii.
//
// Dzięki niej test przechodzi całą drogę „zrób zdjęcie → zmniejsz → wyślij"
// bez kamery, bez uprawnień i bez platformy natywnej (decyzja 335). Odmowę
// systemu też da się odtworzyć — a to ścieżka, której na emulatorze nie
// zobaczyłbym w ogóle.

import 'package:cinema/core/photo_picker.dart';

class FakePhotoPicker implements PhotoPicker {
  FakePhotoPicker({
    this.result = PhotoResult.picked,
    this.bytes = const <int>[0xFF, 0xD8, 0xFF, 0xE0, 7, 7, 7],
  });

  /// Czym ma się skończyć sięgnięcie po zdjęcie.
  final PhotoResult result;

  /// Bajty „zmniejszonego" JPEG-a.
  final List<int> bytes;

  int calls = 0;
  PhotoOrigin? origin;

  @override
  Future<PhotoOutcome> take(PhotoOrigin origin) async {
    calls++;
    this.origin = origin;
    return result == PhotoResult.picked
        ? PhotoOutcome(result, photo: PickedPhoto(bytes: bytes))
        : PhotoOutcome(result);
  }
}

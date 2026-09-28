// Atrapa udostępniania pliku.
//
// Ta sama zasada co przy arkuszu płatności (decyzja 315, potem 327): kod
// natywny stoi za interfejsem, więc test przechodzi całą drogę „pobierz PDF
// i podaj go systemowi” bez platformy i bez sieci.

import 'package:cinema/core/file_share.dart';

class FakeFileShare implements FileShare {
  int calls = 0;
  String? filename;
  String? mime;
  List<int> bytes = const <int>[];

  @override
  Future<void> shareBytes({
    required String filename,
    required List<int> bytes,
    required String mime,
  }) async {
    calls++;
    this.filename = filename;
    this.bytes = bytes;
    this.mime = mime;
  }
}

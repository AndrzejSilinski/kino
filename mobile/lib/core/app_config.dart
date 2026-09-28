// Konfiguracja pochodząca z buildu, nie z kodu (decyzja 256).
//
// Ten sam kod uruchamiamy na telefonie przez `adb reverse` (localhost:8080),
// na emulatorze (10.0.2.2) i w CI, więc adres API jest parametrem buildu:
//
//   flutter build apk --debug --dart-define=API_BASE_URL=http://localhost:8080
//
// W aplikacji nie ma żadnych sekretów: klucz Reverba i konfiguracja płatności
// przychodzą z API (`/client-config`, odpowiedź checkoutu).

/// Adres API i składanie z niego adresów zasobów.
class AppConfig {
  const AppConfig(this.apiBaseUrl);

  /// Konfiguracja z `--dart-define`. Rzuca [FormatException] przy złym adresie,
  /// żeby literówka w parametrze buildu wyszła od razu przy starcie, a nie przy
  /// pierwszym żądaniu.
  factory AppConfig.fromEnvironment() => AppConfig(parseBaseUrl(_rawBaseUrl));

  static const String _rawBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://localhost:8080',
  );

  /// Prefiks API ustalony w Etapie 3 (decyzja 14).
  static const String apiPrefix = '/api/v1';

  final Uri apiBaseUrl;

  /// Sprawdza schemat i obcina końcowy ukośnik, żeby składanie ścieżek nie
  /// dawało podwójnych ukośników.
  static Uri parseBaseUrl(String value) {
    final Uri parsed = Uri.parse(value.trim());
    if (parsed.scheme != 'http' && parsed.scheme != 'https') {
      throw FormatException('API_BASE_URL musi być http albo https', value);
    }
    if (!parsed.hasAuthority) {
      throw FormatException('API_BASE_URL musi zawierać host', value);
    }
    if (parsed.hasQuery || parsed.hasFragment) {
      throw FormatException('API_BASE_URL nie może mieć parametrów', value);
    }
    final String path = parsed.path.endsWith('/')
        ? parsed.path.substring(0, parsed.path.length - 1)
        : parsed.path;
    return parsed.replace(path: path);
  }

  /// Adres endpointu API, np. `apiUri('/client-config')`.
  Uri apiUri(String path, {Map<String, String>? query}) {
    if (!path.startsWith('/')) {
      throw ArgumentError.value(path, 'path', 'ścieżka musi zaczynać się od /');
    }
    return apiBaseUrl.replace(
      path: '${apiBaseUrl.path}$apiPrefix$path',
      queryParameters: query,
    );
  }

  /// Czy adres jest bez TLS. Na Androidzie dopuszczamy to tylko w buildzie
  /// deweloperskim (HTTPS i `wss://` wchodzą w Etapie 10).
  bool get isCleartext => apiBaseUrl.scheme == 'http';

  /// Czy dany adres absolutny z API (plakat, avatar, `qr_url`) wskazuje na nasz
  /// serwer. Token bearer wolno dołączyć TYLKO do takiego adresu (decyzja 257).
  bool isSameOrigin(Uri uri) =>
      uri.scheme == apiBaseUrl.scheme &&
      uri.host == apiBaseUrl.host &&
      uri.port == apiBaseUrl.port;
}

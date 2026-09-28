// Odczyt pól JSON ze strażnikami.
//
// Modele piszemy ręcznie (decyzja 260), więc każde pole przechodzi przez jedną
// z tych funkcji. Brak pola albo inny typ = INVALID_RESPONSE z informacją,
// gdzie dokładnie kontrakt się rozjechał. Bez tego błąd kontraktu objawiałby
// się dopiero jako wyjątek rzutowania gdzieś w widgecie.

import 'package:cinema/core/api_error.dart';

/// Mapa albo INVALID_RESPONSE.
Map<String, Object?> jsonMap(Object? value, String where) {
  if (value is Map<String, Object?>) {
    return value;
  }
  throw ApiError.malformedResponse(where);
}

/// Lista albo INVALID_RESPONSE.
List<Object?> jsonList(Object? value, String where) {
  if (value is List<Object?>) {
    return value;
  }
  throw ApiError.malformedResponse(where);
}

String jsonString(Map<String, Object?> map, String key, String where) {
  final Object? value = map[key];
  if (value is String) {
    return value;
  }
  throw ApiError.malformedResponse('$where.$key');
}

String? jsonStringOrNull(Map<String, Object?> map, String key, String where) {
  final Object? value = map[key];
  if (value == null) {
    return null;
  }
  if (value is String) {
    return value;
  }
  throw ApiError.malformedResponse('$where.$key');
}

int jsonInt(Map<String, Object?> map, String key, String where) {
  final Object? value = map[key];
  if (value is int) {
    return value;
  }
  throw ApiError.malformedResponse('$where.$key');
}

/// Liczba albo `null` — np. `lock_expires_in_seconds` przy cudzej blokadzie
/// i `category.id` przy miejscu bez kategorii cenowej.
int? jsonIntOrNull(Map<String, Object?> map, String key, String where) {
  final Object? value = map[key];
  if (value == null) {
    return null;
  }
  if (value is int) {
    return value;
  }
  throw ApiError.malformedResponse('$where.$key');
}

bool jsonBool(Map<String, Object?> map, String key, String where) {
  final Object? value = map[key];
  if (value is bool) {
    return value;
  }
  throw ApiError.malformedResponse('$where.$key');
}

/// Zagnieżdżony obiekt, np. `data.realtime`.
Map<String, Object?> jsonChild(
  Map<String, Object?> map,
  String key,
  String where,
) => jsonMap(map[key], '$where.$key');

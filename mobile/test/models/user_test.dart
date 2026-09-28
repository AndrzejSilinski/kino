import 'package:cinema/core/api_error.dart';
import 'package:cinema/models/user.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, Object?> payload() => <String, Object?>{
  'id': 13,
  'name': 'Andrzej',
  'email': 'klient@example.com',
  'avatar_url': null,
  'role': 'customer',
  'role_label': 'Klient',
  'created_at': '2026-08-01T12:00:00+02:00',
};

void main() {
  test('czyta konto z odpowiedzi /auth/me', () {
    final User user = User.fromJson(payload());

    expect(user.id, 13);
    expect(user.name, 'Andrzej');
    expect(user.role, 'customer');
    expect(user.roleLabel, 'Klient');
    expect(user.avatarUrl, isNull);
    expect(user.createdAt?.date, '2026-08-01');
    expect(user.initial, 'A');
  });

  test('brak wymaganego pola to INVALID_RESPONSE', () {
    final Map<String, Object?> broken = payload()..remove('role_label');

    try {
      User.fromJson(broken);
      fail('oczekiwano ApiError');
    } on ApiError catch (error) {
      expect(error.code, ApiError.invalidResponse);
      expect(error.context['where'], 'user.role_label');
    }
  });
}

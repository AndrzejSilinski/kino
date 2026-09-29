// Ekran konta: zdjęcie, nazwa, hasło — cała droga bez kamery i bez sieci.
//
// Ekran jest długi (zdjęcie, nazwa, trzy pola hasła), a `ListView` montuje
// tylko to, co widać — więc od razu ustawiamy wysoki widok (pułapka DX).

import 'dart:convert';

import 'package:cinema/core/photo_picker.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/core/session.dart';
import 'package:cinema/features/account/account_screen.dart';
import 'package:cinema/state/account.dart';
// Bez `state/auth.dart`: `authProvider` mieszka w providers.dart, a nazw
// `AuthStatus`/`AuthController` ten plik nie używa (pułapka DY).
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fake_photo_picker.dart';

const Timeout limit = Timeout(Duration(seconds: 30));
const String token = '3|tokenTestowyEkranuKonta';

Map<String, Object?> userEnvelope({String name = 'Andrzej', String? avatar}) =>
    <String, Object?>{
      'data': <String, Object?>{
        'id': 7,
        'name': name,
        'email': 'klient@example.com',
        'avatar_url': avatar,
        'role': 'customer',
        'role_label': 'Klient',
        'created_at': '2026-09-01T10:00:00+00:00',
      },
    };

http.Response reply(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

class FakeAccountApi {
  FakeAccountApi({this.onProfile});

  final http.Response Function()? onProfile;

  int profileCalls = 0;
  int passwordCalls = 0;
  int avatarPosts = 0;

  http.Response handle(http.Request request) {
    final String path = request.url.path;
    if (path.endsWith('/auth/me')) {
      return reply(userEnvelope());
    }
    if (path.endsWith('/account/profile')) {
      profileCalls++;
      return onProfile?.call() ??
          reply(
            userEnvelope(
              name:
                  (jsonDecode(request.body) as Map<String, Object?>)['name']!
                      as String,
            ),
          );
    }
    if (path.endsWith('/account/password')) {
      passwordCalls++;
      return reply(<String, Object?>{
        'data': <String, Object?>{
          'message': 'Hasło zostało zmienione.',
          'revoked_tokens': 0,
        },
      });
    }
    avatarPosts++;
    return reply(
      userEnvelope(avatar: 'http://localhost:8080/storage/avatars/abc.jpg'),
    );
  }
}

void tallView(WidgetTester tester) {
  tester.view.physicalSize = const Size(800, 1800);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

Future<Widget> screenWith(FakeAccountApi api, {FakePhotoPicker? picker}) async {
  final AppSession session = AppSession(InMemorySecureStore());
  await session.setToken(token);
  return ProviderScope(
    retry: noRetry,
    overrides: [
      httpClientProvider.overrideWithValue(
        MockClient((http.Request request) async => api.handle(request)),
      ),
      secureStoreProvider.overrideWithValue(InMemorySecureStore()),
      sessionProvider.overrideWithValue(session),
      photoPickerProvider.overrideWithValue(picker ?? FakePhotoPicker()),
    ],
    child: const MaterialApp(home: AccountScreen()),
  );
}

/// Pola w kolejności z ekranu: nazwa, obecne hasło, nowe, powtórzone.
Finder fieldAt(int index) => find.byType(TextField).at(index);

void main() {
  testWidgets(
    'pokazuje dane konta i inicjał, gdy nie ma zdjęcia',
    timeout: limit,
    (WidgetTester tester) async {
      tallView(tester);

      await tester.pumpWidget(await screenWith(FakeAccountApi()));
      await tester.pumpAndSettle();

      // Nazwa jest w dwóch miejscach: w nagłówku i w polu do edycji —
      // a `find.text` widzi też zawartość pól tekstowych.
      expect(find.text('Andrzej'), findsWidgets);
      expect(find.text('klient@example.com'), findsOneWidget);
      expect(find.text('Klient'), findsOneWidget);
      // Bez zdjęcia kółko pokazuje pierwszą literę nazwy.
      expect(find.text('A'), findsOneWidget);
      expect(find.text('Zrób zdjęcie'), findsOneWidget);
      // Nie ma czego usuwać, więc nie ma przycisku usuwania.
      expect(find.text('Usuń zdjęcie'), findsNothing);
    },
  );

  testWidgets('zdjęcie z aparatu leci na serwer', timeout: limit, (
    WidgetTester tester,
  ) async {
    tallView(tester);
    final FakePhotoPicker picker = FakePhotoPicker();
    final FakeAccountApi api = FakeAccountApi();
    await tester.pumpWidget(await screenWith(api, picker: picker));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Zrób zdjęcie'));
    await tester.pumpAndSettle();

    expect(picker.origin, PhotoOrigin.camera);
    expect(api.avatarPosts, 1);
    expect(find.text('Zdjęcie zostało zapisane.'), findsOneWidget);
    // Po wgraniu jest co usuwać.
    expect(find.text('Usuń zdjęcie'), findsOneWidget);
  });

  testWidgets('odmowa systemu mówi, gdzie włączyć zgodę', timeout: limit, (
    WidgetTester tester,
  ) async {
    tallView(tester);
    final FakeAccountApi api = FakeAccountApi();
    await tester.pumpWidget(
      await screenWith(
        api,
        picker: FakePhotoPicker(result: PhotoResult.denied),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('Wybierz z galerii'));
    await tester.pumpAndSettle();

    expect(find.textContaining('ustawieniach telefonu'), findsOneWidget);
    expect(api.avatarPosts, 0);
  });

  testWidgets('zapis nazwy wysyła PATCH i potwierdza', timeout: limit, (
    WidgetTester tester,
  ) async {
    tallView(tester);
    final FakeAccountApi api = FakeAccountApi();
    await tester.pumpWidget(await screenWith(api));
    await tester.pumpAndSettle();

    await tester.enterText(fieldAt(0), 'Andrzej S.');
    await tester.tap(find.text('Zapisz nazwę'));
    await tester.pumpAndSettle();

    expect(api.profileCalls, 1);
    expect(find.text('Nazwa została zmieniona.'), findsOneWidget);
  });

  testWidgets('błąd walidacji nazwy pokazuje się POD POLEM', timeout: limit, (
    WidgetTester tester,
  ) async {
    tallView(tester);
    final FakeAccountApi api = FakeAccountApi(
      onProfile: () => reply(<String, Object?>{
        'message': 'Podane dane są nieprawidłowe.',
        'code': 'VALIDATION_FAILED',
        'errors': <String, Object?>{
          'name': <String>['Nazwa musi mieć co najmniej 2 znaki.'],
        },
      }, 422),
    );
    await tester.pumpWidget(await screenWith(api));
    await tester.pumpAndSettle();

    await tester.enterText(fieldAt(0), 'A');
    await tester.tap(find.text('Zapisz nazwę'));
    await tester.pumpAndSettle();

    // Pod polem, a nie tylko w pasku u góry — inaczej klient musiałby
    // zgadywać, o które z czterech pól chodzi.
    expect(find.text('Nazwa musi mieć co najmniej 2 znaki.'), findsOneWidget);
  });

  testWidgets('niezgodne powtórzenie hasła NIE rusza serwera', timeout: limit, (
    WidgetTester tester,
  ) async {
    tallView(tester);
    final FakeAccountApi api = FakeAccountApi();
    await tester.pumpWidget(await screenWith(api));
    await tester.pumpAndSettle();

    await tester.enterText(fieldAt(1), 'stareHaslo1');
    await tester.enterText(fieldAt(2), 'noweHaslo2');
    await tester.enterText(fieldAt(3), 'noweHaslo3');
    await tester.tap(find.text('Zmień hasło'));
    await tester.pumpAndSettle();

    expect(api.passwordCalls, 0);
    expect(find.textContaining('różni się'), findsOneWidget);
  });

  testWidgets('udana zmiana hasła CZYŚCI pola', timeout: limit, (
    WidgetTester tester,
  ) async {
    // Hasło zostawione w polu widzi każdy, kto weźmie telefon do ręki.
    tallView(tester);
    final FakeAccountApi api = FakeAccountApi();
    await tester.pumpWidget(await screenWith(api));
    await tester.pumpAndSettle();

    await tester.enterText(fieldAt(1), 'stareHaslo1');
    await tester.enterText(fieldAt(2), 'noweHaslo2');
    await tester.enterText(fieldAt(3), 'noweHaslo2');
    await tester.tap(find.text('Zmień hasło'));
    await tester.pumpAndSettle();

    expect(api.passwordCalls, 1);
    expect(find.text('Hasło zostało zmienione.'), findsOneWidget);
    for (int i = 1; i <= 3; i++) {
      expect(tester.widget<TextField>(fieldAt(i)).controller!.text, isEmpty);
    }
  });

  testWidgets('komunikat da się schować', timeout: limit, (
    WidgetTester tester,
  ) async {
    tallView(tester);
    await tester.pumpWidget(await screenWith(FakeAccountApi()));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Zrób zdjęcie'));
    await tester.pumpAndSettle();
    expect(find.text('Zdjęcie zostało zapisane.'), findsOneWidget);

    await tester.tap(find.byTooltip('Zamknij'));
    await tester.pumpAndSettle();

    expect(find.text('Zdjęcie zostało zapisane.'), findsNothing);
  });
}

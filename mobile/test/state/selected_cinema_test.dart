// Wybór kina ma przeżyć zamknięcie aplikacji — inaczej przy każdym wejściu
// trzeba by zaczynać od listy miast.

import 'package:cinema/core/secure_store.dart';
import 'package:cinema/state/catalog.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

ProviderContainer containerWith(InMemorySecureStore store) {
  final ProviderContainer container = ProviderContainer(
    retry: noRetry,
    overrides: [secureStoreProvider.overrideWithValue(store)],
  );
  addTearDown(container.dispose);
  return container;
}

void main() {
  test('zapisuje wybrane kino w magazynie', () async {
    final InMemorySecureStore store = InMemorySecureStore();
    final ProviderContainer container = containerWith(store);

    await container
        .read(selectedCinemaProvider.notifier)
        .select('gdansk-kino-baltyk');

    expect(container.read(selectedCinemaProvider), 'gdansk-kino-baltyk');
    expect(await store.read(SelectedCinema.storeKey), 'gdansk-kino-baltyk');
  });

  test('odczytuje wybór z poprzedniego uruchomienia', () async {
    final InMemorySecureStore store = InMemorySecureStore();
    await store.write(SelectedCinema.storeKey, 'warszawa-kino-atlantyk');
    final ProviderContainer container = containerWith(store);

    container.read(selectedCinemaProvider);
    await Future<void>.delayed(Duration.zero);

    expect(container.read(selectedCinemaProvider), 'warszawa-kino-atlantyk');
  });
}

// Jeden sposób pokazywania stanu ładowania i błędu na wszystkich ekranach.
//
// Bez tego każdy ekran miałby własny wariant komunikatu, a przy wyłączonym
// automatycznym ponawianiu (decyzja 270) użytkownik MUSI mieć przycisk
// „Spróbuj ponownie” wszędzie tam, gdzie coś przychodzi z sieci.

import 'package:cinema/core/api_error.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class AsyncView<T> extends StatelessWidget {
  const AsyncView({
    required this.value,
    required this.builder,
    required this.onRetry,
    super.key,
  });

  final AsyncValue<T> value;
  final Widget Function(T data) builder;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return value.when(
      data: builder,
      loading: () => const Center(
        child: Padding(
          padding: EdgeInsets.all(32),
          child: CircularProgressIndicator(),
        ),
      ),
      error: (Object error, StackTrace stackTrace) =>
          _Failure(error: error, onRetry: onRetry),
    );
  }
}

class _Failure extends StatelessWidget {
  const _Failure({required this.error, required this.onRetry});

  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final Object thrown = error;
    final ApiError? apiError = thrown is ApiError ? thrown : null;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(
              apiError?.message ?? 'Nie udało się pobrać danych.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 8),
            Text('Kod: ${apiError?.code ?? thrown.runtimeType}'),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: onRetry,
              child: const Text('Spróbuj ponownie'),
            ),
          ],
        ),
      ),
    );
  }
}

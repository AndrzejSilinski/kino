// Plakat filmu. W danych deweloperskich `poster_url` bywa puste, a na
// telefonie obraz może się nie pobrać (brak sieci, wygasła sesja tunelu adb),
// więc każdy plakat ma zastępnik i nigdy nie wywraca listy.
//
// Zastępnikiem jest ikona, nie tytuł (decyzja 283 i pułapka CW): tytuł stoi
// zawsze obok plakatu, więc napis w środku ramki dublowałby go na ekranie i w
// czytniku ekranu. Plakat jest czystą dekoracją — nie wnosi własnej semantyki.

import 'package:flutter/material.dart';

class Poster extends StatelessWidget {
  const Poster({required this.url, super.key, this.width = 64});

  final String? url;
  final double width;

  @override
  Widget build(BuildContext context) {
    final String? address = url;
    return ClipRRect(
      borderRadius: BorderRadius.circular(8),
      child: SizedBox(
        width: width,
        height: width * 1.5,
        child: address == null
            ? const _Placeholder()
            : Image.network(
                address,
                fit: BoxFit.cover,
                errorBuilder: (
                  BuildContext context,
                  Object error,
                  StackTrace? stack,
                ) => const _Placeholder(),
                loadingBuilder:
                    (
                      BuildContext context,
                      Widget child,
                      ImageChunkEvent? progress,
                    ) => progress == null
                    ? child
                    : const ColoredBox(color: Color(0x11000000)),
              ),
      ),
    );
  }
}

class _Placeholder extends StatelessWidget {
  const _Placeholder();

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return ColoredBox(
      color: colors.surfaceContainerHighest,
      child: Center(
        child: Icon(Icons.movie_outlined, color: colors.onSurfaceVariant),
      ),
    );
  }
}

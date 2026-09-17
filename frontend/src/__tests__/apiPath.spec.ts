import { describe, expect, it } from 'vitest';
import { apiPathFromUrl } from '@/lib/apiPath';

const ORIGIN = 'http://localhost:8080';

describe('ścieżka API z adresu podanego przez serwer', () => {
  it('pełny adres z naszego originu -> ścieżka względem /api/v1 (z parametrami)', () => {
    expect(apiPathFromUrl('http://localhost:8080/api/v1/bookings/01M2QM60X2F0WC7SXBC2E7Q7VA/tickets/7/qr', ORIGIN))
      .toBe('/bookings/01M2QM60X2F0WC7SXBC2E7Q7VA/tickets/7/qr');
    expect(apiPathFromUrl('/api/v1/bookings/X/tickets/7/qr?v=2', ORIGIN)).toBe('/bookings/X/tickets/7/qr?v=2');
  });

  it.each([
    ['obcy host (token trafiłby do obcego serwera)', 'https://evil.example/api/v1/bookings/X/tickets/7/qr'],
    ['ten sam host, inny port', 'http://localhost:9999/api/v1/bookings/X/tickets/7/qr'],
    ['adres bez protokołu //host', '//evil.example/api/v1/x'],
    ['ścieżka spoza API', 'http://localhost:8080/admin/bookings'],
    ['prefiks podobny do API', 'http://localhost:8080/api/v10/x'],
  ])('odrzuca: %s', (_, url) => {
    expect(apiPathFromUrl(url, ORIGIN)).toBeNull();
  });
});

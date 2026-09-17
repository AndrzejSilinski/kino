/*
 * Wstępne sprawdzenie wybranego avatara w przeglądarce (Etap 8, blok J).
 *
 * To wygoda, nie zabezpieczenie: te same zasady (JPG/PNG, do 5 MB) sprawdza serwer, a obraz
 * i tak przekodowuje GD. Dzięki temu klient z telefonem na słabym łączu nie wysyła 12 MB
 * tylko po to, żeby dostać 422.
 */
export const AVATAR_MAX_BYTES = 5 * 1024 * 1024;
export const AVATAR_TYPES = ['image/jpeg', 'image/png'] as const;

export function avatarFileProblem(file: Pick<File, 'type' | 'size'>): string | null {
  if (!(AVATAR_TYPES as readonly string[]).includes(file.type)) {
    return 'Avatar musi być plikiem JPG albo PNG.';
  }
  if (file.size > AVATAR_MAX_BYTES) {
    return 'Avatar nie może być większy niż 5 MB.';
  }
  return null;
}

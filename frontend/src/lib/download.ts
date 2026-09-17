/*
 * Zapis pliku pobranego przez fetch (blok H4): PDF z biletami idzie z tokenem bearer, więc zwykły
 * <a href> nie wystarczy (nie wyśle nagłówka Authorization). Blob -> adres obiektu -> kliknięcie
 * w ukryty link z atrybutem download -> zwolnienie adresu.
 */
export function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.rel = 'noopener';
  document.body.append(link);
  link.click();
  link.remove();
  // Część przeglądarek zaczyna pobieranie asynchronicznie — zwolnienie od razu potrafi je przerwać.
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

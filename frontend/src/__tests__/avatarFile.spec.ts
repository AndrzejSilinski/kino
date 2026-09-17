import { describe, expect, it } from 'vitest';
import { AVATAR_MAX_BYTES, avatarFileProblem } from '@/lib/avatarFile';

describe('wstępne sprawdzenie avatara', () => {
  it('JPG i PNG do 5 MB przechodzą', () => {
    expect(avatarFileProblem({ type: 'image/jpeg', size: AVATAR_MAX_BYTES })).toBeNull();
    expect(avatarFileProblem({ type: 'image/png', size: 1000 })).toBeNull();
  });

  it('inny typ albo za duży plik — komunikat zanim cokolwiek pójdzie do sieci', () => {
    expect(avatarFileProblem({ type: 'image/webp', size: 1000 })).toBe('Avatar musi być plikiem JPG albo PNG.');
    expect(avatarFileProblem({ type: 'image/png', size: AVATAR_MAX_BYTES + 1 })).toBe('Avatar nie może być większy niż 5 MB.');
  });
});

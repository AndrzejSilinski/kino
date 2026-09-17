import type { User } from '@/api/types';

export function user(overrides: Partial<User> = {}): User {
  return { id: 7, name: 'Anna Nowak', email: 'anna@example.com', role: 'customer', role_label: 'Klient', avatar_url: null, created_at: null, ...overrides };
}

export const AVATAR_URL = `http://localhost:3000/storage/avatars/${'a1'.repeat(20)}.jpg`;

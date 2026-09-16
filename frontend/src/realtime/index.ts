/*
 * Jedno połączenie WebSocket na całą aplikację (Etap 8, blok G), tworzone przy pierwszym użyciu.
 * Klucz Reverba z GET /api/v1/client-config — ten sam build działa w każdym środowisku.
 */
import { http } from '@/api/client';
import { loadClientConfig } from '@/api/clientConfig';
import { createPusherConnection, type RealtimeConnection } from './connection';

let connection: Promise<RealtimeConnection> | null = null;

export function getRealtimeConnection(): Promise<RealtimeConnection> {
  connection ??= loadClientConfig()
    .then((config) => createPusherConnection({ key: config.realtime.key, http }))
    .catch((error: unknown) => {
      connection = null;
      throw error;
    });
  return connection;
}

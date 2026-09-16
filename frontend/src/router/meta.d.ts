import 'vue-router';

declare module 'vue-router' {
  interface RouteMeta {
    /** Tytuł karty przeglądarki. */
    title?: string;
    /** Trasa tylko dla zalogowanych — strażnik przekieruje na logowanie z ?redirect=. */
    requiresAuth?: boolean;
    /** Trasa tylko dla gości (logowanie, rejestracja). */
    guestOnly?: boolean;
  }
}

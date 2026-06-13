/**
 * Analytics Layer — Zenith Wellness
 *
 * Provides a unified interface for:
 * - Google Analytics 4 (GA4) — page views, events, user properties
 * - Meta Pixel — conversion tracking, page views, custom events
 * - Server-side CAPI (Conversion API) — privacy-resilient event matching
 *
 * The `track()` function is the primary entry point for all client-side
 * analytics events. Events are forwarded to GA4, Meta Pixel, and CAPI.
 */

import { api } from '../services/api';

// ─── Types ───────────────────────────────────────────────────────────────────

export interface AnalyticsEvent {
  name: string;
  properties?: Record<string, string | number | boolean>;
}

export interface AnalyticsConfig {
  ga4MeasurementId?: string;
  metaPixelId?: string;
  capiEndpoint?: string;
  capiAccessToken?: string;
  debugMode?: boolean;
}

// ─── Configuration ───────────────────────────────────────────────────────────

const config: AnalyticsConfig = {
  ga4MeasurementId: '', // Set via window.__ZENITH_ANALYTICS__ or env
  metaPixelId: '',       // Set via window.__ZENITH_ANALYTICS__ or env
  debugMode: import.meta.env.DEV,
};

const CAPI_ENDPOINT = '/api/capi';

// ─── GA4 Helpers ─────────────────────────────────────────────────────────────

function sendToGA4(eventName: string, properties?: Record<string, string | number | boolean>) {
  if (!config.ga4MeasurementId) return;

  const eventParams = properties ?? {};
  if (config.debugMode) {
    console.debug('[GA4]', eventName, eventParams);
    return;
  }

  // gtag() is loaded via the GA4 script tag in index.html
  if (typeof window !== 'undefined' && (window as any).gtag) {
    (window as any).gtag('event', eventName, eventParams);
  }
}

// ─── Meta Pixel Helpers ───────────────────────────────────────────────────────

function sendToMetaPixel(eventName: string, properties?: Record<string, string | number | boolean>) {
  if (!config.metaPixelId) return;

  const metaParams = properties ?? {};
  if (config.debugMode) {
    console.debug('[Meta Pixel]', eventName, metaParams);
    return;
  }

  if (typeof window !== 'undefined' && (window as any).fbq) {
    (window as any).fbq('track', eventName, metaParams);
  }
}

// ─── CAPI (Server-side) ───────────────────────────────────────────────────────

async function sendToCAPI(
  eventName: string,
  properties?: Record<string, string | number | boolean>
) {
  if (!config.capiEndpoint) return;

  try {
    await api.post(CAPI_ENDPOINT, {
      event: eventName,
      properties,
      source: 'client',
      timestamp: new Date().toISOString(),
    });
  } catch (err) {
    console.warn('[CAPI] Failed to send event:', err);
  }
}

// ─── Public API ───────────────────────────────────────────────────────────────

/**
 * Track an analytics event across all configured providers.
 *
 * Usage:
 *   import { track } from './analytics';
 *   track('page_view', { page: 'login' });
 *   track('signup_completed', { method: 'email' });
 *   track('purchase', { value: 89, currency: 'USD' });
 */
export function track(
  eventName: string,
  properties?: Record<string, string | number | boolean>
) {
  sendToGA4(eventName, properties);
  sendToMetaPixel(eventName, properties);
  // CAPI is fire-and-forget to avoid blocking the user-facing flow
  void sendToCAPI(eventName, properties);
}

/**
 * Initialize the analytics layer.
 * Call once on app mount (e.g., in App.tsx or main entry).
 *
 * @param overrides  Partial config to merge — useful for runtime config injection.
 */
export function initAnalytics(overrides?: Partial<AnalyticsConfig>) {
  Object.assign(config, overrides);

  // Expose track globally for non-Module contexts if needed
  if (typeof window !== 'undefined') {
    (window as any).__zenithTrack = track;
  }
}

// ─── Pre-built event helpers ─────────────────────────────────────────────────

export const analytics = {
  /**
   * Track a page view / route change.
   */
  pageView(pageName: string, properties?: Record<string, string | number | boolean>) {
    track('page_view', { page: pageName, ...properties });
  },

  /**
   * Track user login.
   */
  login(method: 'email' | 'google' | 'social') {
    track('login', { method });
  },

  /**
   * Track user registration.
   */
  signUp(method: 'email' | 'google' | 'social', plan?: string) {
    track('sign_up', { method, plan });
  },

  /**
   * Track cohort/program view.
   */
  cohortView(cohortId: string, cohortTitle: string) {
    track('cohort_view', { cohort_id: cohortId, cohort_title: cohortTitle });
  },

  /**
   * Track quiz completion.
   */
  quizCompleted(quizResult: string, emailProvided: boolean) {
    track('quiz_completed', { quiz_result: quizResult, email_provided: emailProvided });
  },

  /**
   * Track challenge/program landing.
   */
  challengeLanding() {
    track('challenge_landing');
  },

  /**
   * Track purchase / payment initiation.
   */
  purchase(value: number, currency: string, programTitle: string) {
    track('purchase', { value, currency, program_title: programTitle });
  },

  /**
   * Track a CTA click on the sales page.
   */
  salesCTAClick(ctaLabel: string, variant?: string) {
    track('cta_click', { cta_label: ctaLabel, variant });
  },

  /**
   * Track dead hero CTA interaction.
   * (DDE-8 TASK 1.2 wire-up)
   */
  heroCTA(heroId: string, action: 'click' | 'hover' | 'view') {
    track('hero_cta_interaction', { hero_id: heroId, action });
  },

  /**
   * Track Meta Pixel PageView — fired on every route change.
   */
  metaPageView() {
    if (!config.metaPixelId) return;
    if (typeof window !== 'undefined' && (window as any).fbq) {
      (window as any).fbq('track', 'PageView');
    }
  },
};

// ─── Global type augmentation ─────────────────────────────────────────────────

declare global {
  interface Window {
    gtag: (...args: any[]) => void;
    fbq: (...args: any[]) => void;
    __zenithTrack: typeof track;
    __ZENITH_ANALYTICS__?: Partial<AnalyticsConfig>;
  }
}
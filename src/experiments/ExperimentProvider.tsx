import { createContext, useContext, useEffect, useCallback, useRef, useState, type ReactNode } from 'react';
import { getVisitorId } from './visitorId';
import { DEFAULT_CONFIGS } from './defaults';

interface ExperimentAssignment {
  variant: string;
  config: Record<string, unknown>;
}

export interface ExperimentContextValue {
  assignments: Record<string, ExperimentAssignment>;
  isReady: boolean;
  getAssignment: (key: string) => ExperimentAssignment | null;
}

const ExperimentContext = createContext<ExperimentContextValue | null>(null);

const CACHE_KEY = 'zen_exp';
const CACHE_TTL = 30 * 60 * 1000;

const DEFAULT_ASSIGNMENTS: Record<string, ExperimentAssignment> = Object.fromEntries(
  (Object.entries(DEFAULT_CONFIGS) as [string, Record<string, unknown>][]).map(([key, config]) => [
    key,
    { variant: 'control', config },
  ])
);

function gatherContext() {
  const params = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : new URLSearchParams();
  const referrer = typeof document !== 'undefined' ? document.referrer : '';
  const source = params.get('utm_source') || params.get('source') || (referrer ? new URL(referrer).hostname : 'direct');

  const ua = typeof navigator !== 'undefined' ? navigator.userAgent : '';
  let device = 'desktop';
  if (/mobile|android|iphone|ipad/i.test(ua)) {
    device = /tablet|ipad/i.test(ua) ? 'tablet' : 'mobile';
  }

  const country = localStorage.getItem('zen_country') || '';
  const quiz_profile = localStorage.getItem('quiz_profile') || '';

  return { source, device, country, quiz_profile };
}

function loadCache(): Record<string, ExperimentAssignment & { _cachedAt: number }> {
  try {
    const raw = localStorage.getItem(CACHE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw);
      const now = Date.now();
      const valid: Record<string, ExperimentAssignment & { _cachedAt: number }> = {};
      for (const [key, val] of Object.entries(parsed)) {
        const entry = val as ExperimentAssignment & { _cachedAt: number };
        if (entry._cachedAt && now - entry._cachedAt < CACHE_TTL) {
          valid[key] = entry;
        }
      }
      return valid;
    }
  } catch {
    // ignore parse errors
  }
  return {};
}

function saveCache(assignments: Record<string, ExperimentAssignment & { _cachedAt: number }>) {
  try {
    localStorage.setItem(CACHE_KEY, JSON.stringify(assignments));
  } catch {
    // quota exceeded — silently ignore
  }
}

export function ExperimentProvider({ children }: { children: ReactNode }) {
  const isReadyRef = useRef(false);
  const assignmentsRef = useRef<Record<string, ExperimentAssignment>>({ ...DEFAULT_ASSIGNMENTS });
  const [, forceUpdate] = useState(0);

  const refresh = useCallback(() => forceUpdate((n) => n + 1), []);

  const getAssignment = useCallback((key: string): ExperimentAssignment | null => {
    return assignmentsRef.current[key] ?? null;
  }, []);

  useEffect(() => {
    const cached = loadCache();
    const now = Date.now();
    const needsFetch: string[] = [];

    for (const key of Object.keys(DEFAULT_ASSIGNMENTS)) {
      if (cached[key]) {
        assignmentsRef.current[key] = { variant: cached[key].variant, config: cached[key].config };
      } else {
        needsFetch.push(key);
      }
    }

    if (needsFetch.length === 0) {
      isReadyRef.current = true;
      refresh();
      return;
    }

    const context = gatherContext();

    fetch('/api/exp/assign', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        visitor_id: getVisitorId(),
        experiments: needsFetch,
        context,
      }),
    })
      .then((res) => (res.ok ? res.json() : Promise.reject()))
      .then((data: Record<string, { variant: string; config: Record<string, unknown> }>) => {
        const updated: Record<string, ExperimentAssignment & { _cachedAt: number }> = {};
        for (const key of needsFetch) {
          const server = data[key];
          if (server) {
            assignmentsRef.current[key] = { variant: server.variant, config: server.config };
            updated[key] = { ...server, _cachedAt: now };
          }
        }
        saveCache({ ...cached, ...updated });
      })
      .catch(() => {
        // server unavailable — defaults are already set
      })
      .finally(() => {
        isReadyRef.current = true;
        refresh();
      });
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <ExperimentContext.Provider value={{ assignments: assignmentsRef.current, isReady: isReadyRef.current, getAssignment }}>
      {children}
    </ExperimentContext.Provider>
  );
}

export function useExperimentContext(): ExperimentContextValue {
  const ctx = useContext(ExperimentContext);
  if (!ctx) {
    throw new Error('useExperimentContext must be used within an ExperimentProvider');
  }
  return ctx;
}

const VISITOR_ID_KEY = 'zen_vid';
const COOKIE_NAME = 'zen_vid';
const COOKIE_MAX_AGE = 365 * 24 * 60 * 60;

function setCookie(name: string, value: string, maxAgeSeconds: number): void {
  if (typeof document === 'undefined') return;
  document.cookie = `${name}=${encodeURIComponent(value)}; Max-Age=${maxAgeSeconds}; Path=/; SameSite=Lax; Secure`;
}

function getCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

export function getVisitorId(): string {
  let vid = getCookie(COOKIE_NAME);

  if (!vid) {
    vid = localStorage.getItem(VISITOR_ID_KEY);
  }

  if (!vid) {
    vid = 'vid_' + crypto.randomUUID().replace(/-/g, '').substring(0, 16);
  }

  setCookie(COOKIE_NAME, vid, COOKIE_MAX_AGE);
  localStorage.setItem(VISITOR_ID_KEY, vid);

  return vid;
}

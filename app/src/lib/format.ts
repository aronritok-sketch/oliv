import type { Session } from './api';

/** "$6" or "$6.50" — whole amounts drop the cents, like on the website. */
export function money(cents: number, currency = 'usd'): string {
  const symbols: Record<string, string> = { usd: '$', eur: '€', gbp: '£', aud: 'A$', cad: 'C$', huf: 'Ft ' };
  const sym = symbols[currency.toLowerCase()] ?? currency.toUpperCase() + ' ';
  const value = cents / 100;
  return sym + (Number.isInteger(value) ? String(value) : value.toFixed(2));
}

export type Day = { date: string; label: string; sessions: Session[] };

/** Groups the schedule by the studio's local date, keeping the server's order. */
export function groupByDay(sessions: Session[]): Day[] {
  const days: Day[] = [];
  for (const s of sessions) {
    const last = days[days.length - 1];
    if (last && last.date === s.local.date) {
      last.sessions.push(s);
    } else {
      days.push({ date: s.local.date, label: s.local.label, sessions: [s] });
    }
  }
  return days;
}

/** "Today", "Tomorrow" or the studio's own label ("Saturday, October 3"). */
export function dayTitle(day: Pick<Day, 'date' | 'label'>, now: Date = new Date()): string {
  const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  if (day.date === iso(now)) return 'Today';
  const tomorrow = new Date(now);
  tomorrow.setDate(now.getDate() + 1);
  if (day.date === iso(tomorrow)) return 'Tomorrow';
  return day.label;
}

/** Short status line for a class card. */
export function availability(s: Session): { text: string; tone: 'ok' | 'low' | 'full' | 'closed' | 'mine' } {
  if (s.my_booking) {
    return { text: s.my_booking.mode === 'online' ? 'Booked · live online' : 'Booked', tone: 'mine' };
  }
  if (s.closed_reason) return { text: 'Booking closed', tone: 'closed' };
  if (s.waitlist_position > 0) return { text: `Waitlist #${s.waitlist_position}`, tone: 'low' };
  const studioFull = s.format !== 'online' && s.spots_left <= 0;
  const onlineOpen = s.format === 'hybrid' && (s.online_spots_left === null || s.online_spots_left > 0);
  if (studioFull && onlineOpen) return { text: 'Studio full · online open', tone: 'low' };
  if (s.spots_left <= 0) return { text: 'Full · join the waitlist', tone: 'full' };
  if (s.format === 'online') return { text: 'Live online', tone: 'ok' };
  if (s.spots_left <= 3) return { text: s.spots_left === 1 ? '1 spot left' : `${s.spots_left} spots left`, tone: 'low' };
  return { text: `${s.spots_left} spots`, tone: 'ok' };
}

/** "$25" or "By donation" for a class card. */
export function priceLabel(s: Session, currency = 'usd'): string {
  if (s.pricing === 'donation') return 'By donation';
  return s.price_cents ? money(s.price_cents, currency) : 'Free';
}

export function formatLabel(format: Session['format']): string {
  return format === 'online' ? 'Online' : format === 'hybrid' ? 'Studio + online' : 'Studio';
}

/** Minutes until a date; negative once it has passed. */
export function minutesUntil(iso: string, now: Date = new Date()): number {
  return Math.round((new Date(iso).getTime() - now.getTime()) / 60000);
}

/** "Oct 3, 2026" from an ISO date. */
export function shortDate(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return `${months[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}`;
}

export function plural(n: number, one: string, many: string): string {
  return `${n} ${n === 1 ? one : many}`;
}

/** Strips the few HTML tags the studio texts may carry (waiver, policy). */
export function plainText(html: string): string {
  return html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/p>\s*<p[^>]*>/gi, '\n\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&#0?39;|&rsquo;/g, '’')
    .replace(/&quot;/g, '"')
    .trim();
}

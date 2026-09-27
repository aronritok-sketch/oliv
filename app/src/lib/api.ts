import { apiBase } from './config';

/* ---------- Shapes returned by the olivia-studio plugin (oys/v1/app/*) ---------- */

export type Mode = 'studio' | 'online';
export type Format = 'studio' | 'online' | 'hybrid';

export type MyBooking = {
  id: number;
  mode: Mode;
  paid_with: string;
  guests: string[];
  can_cancel: boolean;
  in_window: boolean;
  /** null: not an online booking; '': opens 60 minutes before the class; otherwise the link. */
  join_url: string | null;
  /** Still to pay at the studio (own spot and guests), in cents. */
  due_cents?: number;
};

export type Session = {
  id: number;
  kind: 'class' | 'workshop' | 'private' | string;
  title: string;
  class_slug: string;
  description: string;
  note: string;
  starts_at: string;
  ends_at: string;
  local: { date: string; day: string; label: string; start: string; end: string };
  duration: number;
  format: Format;
  location: string;
  capacity: number;
  spots_left: number;
  online_spots_left: number | null;
  price_cents: number;
  online_price_cents: number | null;
  credits_allowed: boolean;
  status: string;
  closed_reason: string;
  my_booking: MyBooking | null;
  pricing?: 'fixed' | 'donation';
  /** "Goes ahead with 3 or more people…" when the class has a minimum, else ''. */
  minimum?: string;
  /** Another teacher's class (null = the studio's own). */
  teacher?: Teacher | null;
  waitlist_position: number;
  web_url: string;
};

export type Teacher = {
  id: number;
  name: string;
  headline: string;
  bio: string;
  photo: string;
  photos: string[];
};

export type PayOption = {
  method: 'membership' | 'credit' | 'card' | 'free' | 'door';
  label: string;
  detail: string;
  price_cents: number;
  available?: number;
};

export type SessionDetail = {
  session: Session;
  /** Donation classes: minimum, suggested amounts and the default, in cents. */
  donation?: { min_cents: number; amounts: number[]; suggested_cents: number } | null;
  mode: Mode;
  spots_left: number;
  price_cents: number;
  options: PayOption[];
  max_guests: number;
  guest_email_required: boolean;
  waiver: string | null;
  policy: string;
};

export type Pass = {
  id: number;
  name: string;
  kind: 'class' | 'online' | 'private' | string;
  credits_left: number;
  credits_total: number;
  expires: string | null;
};

export type Me = {
  user: { id: number; first_name: string; last_name: string; name: string; email: string; phone: string; newsletter?: boolean };
  balances: { class: number; online: number; private: number };
  passes: Pass[];
  membership: {
    name: string;
    status: string;
    classes_per_period: number;
    remaining: number | null;
    period_end: string | null;
    cancel_at_period_end: boolean;
  } | null;
  waiver: { accepted: boolean; text: string };
  studio: {
    name: string;
    cancel_policy: string;
    currency: string;
    max_guests: number;
    online_per_credit: number;
  };
  /** Loyalty draw: classes this period = tickets. */
  raffle?: { tickets: number; label: string; draw: string; prize: string } | null;
  /** The customer's own discount codes (birthday gift…). */
  coupons?: { code: string; label: string; expires: string | null; birthday: boolean }[];
  links: Record<'account' | 'passes' | 'membership' | 'private' | 'pricing' | 'gifts' | 'website' | 'password' | 'signup', string> & {
    /** The studio's Facebook group ('' when not set). */
    community?: string;
  };
};

export type BookingItem = Session & {
  booking: {
    id: number;
    status: string;
    mode: Mode;
    paid_with: string;
    guests: { id: number; name: string }[];
    can_cancel: boolean;
    in_window: boolean;
    join_url: string | null;
  };
};

export type Guest = { name: string; email?: string };

export type BookResult =
  | { status: 'booked'; session: Session }
  | { status: 'checkout'; url: string; order_id: number };

/* ---------- Client ---------- */

export class ApiError extends Error {
  code: string;
  status: number;
  data: Record<string, unknown>;

  constructor(message: string, code: string, status: number, data: Record<string, unknown> = {}) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.data = data;
  }
}

type Options = {
  method?: 'GET' | 'POST';
  body?: unknown;
  query?: Record<string, string | number | undefined>;
};

export type Client = ReturnType<typeof createClient>;

/**
 * A small fetch wrapper. `getToken` is read on every call so a fresh login is picked up;
 * `onUnauthorized` runs when the server no longer accepts the token (revoked, or the person
 * signed out on another device).
 */
export function createClient(
  getToken: () => string | null,
  onUnauthorized: () => void = () => {},
  base: string = apiBase(),
  fetchImpl: typeof fetch = (...args) => fetch(...args),
) {
  const root = base.replace(/\/+$/, '');
  async function request<T>(path: string, { method = 'GET', body, query }: Options = {}): Promise<T> {
    const qs = query
      ? Object.entries(query)
          .filter(([, v]) => v !== undefined && v !== '')
          .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
          .join('&')
      : '';
    const url = `${root}/wp-json/oys/v1/app${path}${qs ? `?${qs}` : ''}`;
    const headers: Record<string, string> = { Accept: 'application/json' };
    const token = getToken();
    if (token) {
      headers.Authorization = `Bearer ${token}`;
      // Some hosts strip the Authorization header; the plugin also reads this one.
      headers['X-OYS-Token'] = token;
    }
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }

    let res: Response;
    try {
      res = await fetchImpl(url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
    } catch {
      throw new ApiError('No connection. Check your internet and try again.', 'network', 0);
    }

    let json: any = null;
    try {
      json = await res.json();
    } catch {
      // Handled below: a non-JSON reply is an error either way.
    }

    if (!res.ok || json === null) {
      const data = (json?.data ?? {}) as Record<string, unknown>;
      const err = new ApiError(
        json?.message || 'Something went wrong. Please try again.',
        json?.code || 'http_' + res.status,
        res.status,
        data,
      );
      if (res.status === 401 && token && path !== '/login') {
        onUnauthorized();
      }
      throw err;
    }
    return json as T;
  }

  return {
    login: (email: string, password: string, device = 'iPhone') =>
      request<{ token: string; me: Me }>('/login', { method: 'POST', body: { email, password, device } }),
    logout: () => request<{ ok: boolean }>('/logout', { method: 'POST' }),
    me: () => request<Me>('/me'),
    acceptWaiver: () => request<Me>('/waiver', { method: 'POST' }),
    schedule: (days?: number) => request<{ sessions: Session[] }>('/schedule', { query: { days } }),
    session: (id: number, mode?: Mode) => request<SessionDetail>(`/sessions/${id}`, { query: { mode } }),
    book: (
      id: number,
      args: { method: PayOption['method']; mode: Mode; guests?: Guest[]; accept_waiver?: boolean; amount_cents?: number },
    ) => request<BookResult>(`/sessions/${id}/book`, { method: 'POST', body: args }),
    waitlist: (id: number, leave = false) =>
      request<{ session: Session }>(`/sessions/${id}/waitlist`, { method: 'POST', body: leave ? { do: 'leave' } : {} }),
    bookings: (when: 'upcoming' | 'past' = 'upcoming') =>
      request<{ bookings: BookingItem[] }>('/bookings', { query: { when: when === 'past' ? 'past' : undefined } }),
    cancel: (bookingId: number) =>
      request<{ outcome: string; message: string }>(`/bookings/${bookingId}/cancel`, { method: 'POST' }),
    newsletter: (subscribe: boolean) => request<Me>('/newsletter', { method: 'POST', body: { subscribe } }),
    pushToken: (token: string, platform: string) =>
      request<{ ok: boolean }>('/push-token', { method: 'POST', body: { token, platform } }),
  };
}

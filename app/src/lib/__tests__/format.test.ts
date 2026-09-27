import type { Session } from '../api';
import { availability, dayTitle, groupByDay, money, plainText, priceLabel } from '../format';

function session(over: Partial<Session> = {}): Session {
  return {
    id: 1,
    kind: 'group',
    title: 'Hatha Flow',
    class_slug: 'hatha-flow',
    description: '',
    note: '',
    starts_at: '2026-10-03T08:00:00+00:00',
    ends_at: '2026-10-03T09:00:00+00:00',
    local: { date: '2026-10-03', day: 'Sat', label: 'Saturday, October 3', start: '10:00 am', end: '11:00 am' },
    duration: 60,
    format: 'studio',
    location: 'Studio 1',
    capacity: 10,
    spots_left: 10,
    online_spots_left: null,
    price_cents: 2500,
    online_price_cents: null,
    credits_allowed: true,
    status: 'scheduled',
    closed_reason: '',
    my_booking: null,
    waitlist_position: 0,
    web_url: '',
    ...over,
  };
}

describe('money', () => {
  it('drops cents on whole amounts', () => {
    expect(money(600)).toBe('$6');
    expect(money(2500, 'usd')).toBe('$25');
  });
  it('keeps cents otherwise', () => {
    expect(money(650)).toBe('$6.50');
  });
  it('uses the studio currency', () => {
    expect(money(1200, 'eur')).toBe('€12');
    expect(money(1200, 'chf')).toBe('CHF 12');
  });
});

describe('groupByDay', () => {
  it('groups consecutive sessions by local date', () => {
    const days = groupByDay([
      session({ id: 1 }),
      session({ id: 2 }),
      session({ id: 3, local: { date: '2026-10-04', day: 'Sun', label: 'Sunday, October 4', start: '9:00 am', end: '10:00 am' } }),
    ]);
    expect(days.map((d) => [d.date, d.sessions.map((s) => s.id)])).toEqual([
      ['2026-10-03', [1, 2]],
      ['2026-10-04', [3]],
    ]);
  });
});

describe('dayTitle', () => {
  const now = new Date(2026, 9, 3, 12, 0);
  it('says today and tomorrow', () => {
    expect(dayTitle({ date: '2026-10-03', label: 'x' }, now)).toBe('Today');
    expect(dayTitle({ date: '2026-10-04', label: 'x' }, now)).toBe('Tomorrow');
  });
  it('falls back to the studio label', () => {
    expect(dayTitle({ date: '2026-10-09', label: 'Friday, October 9' }, now)).toBe('Friday, October 9');
  });
});

describe('availability', () => {
  it('shows my booking first', () => {
    const mine = { id: 5, mode: 'online' as const, paid_with: 'Pass', guests: [], can_cancel: true, in_window: false, join_url: '' };
    expect(availability(session({ my_booking: mine }))).toEqual({ text: 'Booked · live online', tone: 'mine' });
  });
  it('flags few spots', () => {
    expect(availability(session({ spots_left: 1 })).text).toBe('1 spot left');
    expect(availability(session({ spots_left: 3 })).tone).toBe('low');
  });
  it('keeps hybrid classes open online when the studio is full', () => {
    expect(availability(session({ format: 'hybrid', spots_left: 0, online_spots_left: null })).text).toBe('Studio full · online open');
    expect(availability(session({ format: 'hybrid', spots_left: 0, online_spots_left: 0 })).tone).toBe('full');
  });
  it('shows the waitlist place and closed classes', () => {
    expect(availability(session({ spots_left: 0, waitlist_position: 2 })).text).toBe('Waitlist #2');
    expect(availability(session({ closed_reason: 'Online booking for this class has closed.' })).tone).toBe('closed');
  });
});

describe('plainText', () => {
  it('turns simple HTML into text', () => {
    expect(plainText('<p>One &amp; two</p><p>Three<br>four</p>')).toBe('One & two\n\nThree\nfour');
  });
});

describe('priceLabel', () => {
  it('shows the price, free or by donation', () => {
    expect(priceLabel(session())).toBe('$25');
    expect(priceLabel(session({ price_cents: 0 }))).toBe('Free');
    expect(priceLabel(session({ pricing: 'donation' }))).toBe('By donation');
  });
});

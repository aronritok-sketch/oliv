import { ApiError, createClient } from '../api';

type Call = { url: string; init: RequestInit };

function fakeFetch(status: number, body: unknown) {
  const calls: Call[] = [];
  const impl = (async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    return {
      ok: status >= 200 && status < 300,
      status,
      json: async () => body,
    } as Response;
  }) as unknown as typeof fetch;
  return { calls, impl };
}

describe('api client', () => {
  it('sends the token both ways and builds the URL', async () => {
    const f = fakeFetch(200, { sessions: [] });
    const api = createClient(() => '7.secret', undefined, 'https://studio.test/', f.impl);
    await api.schedule(14);
    expect(f.calls[0].url).toBe('https://studio.test/wp-json/oys/v1/app/schedule?days=14');
    const headers = f.calls[0].init.headers as Record<string, string>;
    expect(headers.Authorization).toBe('Bearer 7.secret');
    expect(headers['X-OYS-Token']).toBe('7.secret');
  });

  it('posts JSON bodies', async () => {
    const f = fakeFetch(200, { status: 'booked', session: {} });
    const api = createClient(() => 't', undefined, 'https://studio.test', f.impl);
    await api.book(12, { method: 'credit', mode: 'online', guests: [{ name: 'Bea' }] });
    expect(f.calls[0].url).toBe('https://studio.test/wp-json/oys/v1/app/sessions/12/book');
    expect(f.calls[0].init.method).toBe('POST');
    expect(JSON.parse(String(f.calls[0].init.body))).toEqual({ method: 'credit', mode: 'online', guests: [{ name: 'Bea' }] });
  });

  it('turns WordPress errors into ApiError with the server message', async () => {
    const f = fakeFetch(409, { code: 'oys_waiver', message: 'Please accept the participation agreement first.', data: { status: 409, waiver: 'Text' } });
    const api = createClient(() => 't', undefined, 'https://studio.test', f.impl);
    const err = await api.book(1, { method: 'free', mode: 'studio' }).catch((e) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect(err.code).toBe('oys_waiver');
    expect(err.status).toBe(409);
    expect(err.message).toBe('Please accept the participation agreement first.');
    expect(err.data.waiver).toBe('Text');
  });

  it('signs out when the token is no longer accepted', async () => {
    const f = fakeFetch(401, { code: 'oys_auth', message: 'Please log in again.', data: { status: 401 } });
    const onUnauthorized = jest.fn();
    const api = createClient(() => 'old', onUnauthorized, 'https://studio.test', f.impl);
    await expect(api.me()).rejects.toThrow('Please log in again.');
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('does not sign out on a wrong password', async () => {
    const f = fakeFetch(401, { code: 'oys_login', message: 'The email or password is not right.' });
    const onUnauthorized = jest.fn();
    const api = createClient(() => null, onUnauthorized, 'https://studio.test', f.impl);
    await expect(api.login('a@b.c', 'x')).rejects.toThrow('not right');
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('reports a lost connection kindly', async () => {
    const impl = (async () => {
      throw new TypeError('Network request failed');
    }) as unknown as typeof fetch;
    const api = createClient(() => 't', undefined, 'https://studio.test', impl);
    const err = await api.me().catch((e) => e);
    expect(err.code).toBe('network');
    expect(err.message).toMatch(/No connection/);
  });
});

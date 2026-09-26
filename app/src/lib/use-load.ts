import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError } from './api';

/**
 * Loads data when the screen comes into focus (so a booking made elsewhere shows up),
 * with pull-to-refresh state and a friendly error.
 */
export function useLoad<T>(load: () => Promise<T>, deps: unknown[] = []) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const loadRef = useRef(load);
  useEffect(() => {
    loadRef.current = load;
  });

  const run = useCallback(async (pull = false) => {
    if (pull) setRefreshing(true);
    try {
      const next = await loadRef.current();
      setData(next);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Something went wrong. Please try again.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  // Reload when the screen is focused again, or when a dependency (e.g. the chosen mode) changes.
  const key = JSON.stringify(deps);
  useFocusEffect(
    useCallback(() => {
      void run();
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [run, key]),
  );

  return { data, setData, error, loading, refreshing, reload: run, refresh: () => run(true) };
}

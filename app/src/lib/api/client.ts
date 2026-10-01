import type { z } from 'zod';

import { useSession } from '@/auth/session';
import { config } from '@/lib/config';

/**
 * An error the API returned, in its documented shape: a message, plus field-level
 * `errors` for validation failures (422). Status 0 means the API couldn't be reached.
 */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    readonly errors: Record<string, string[]> = {},
    /** Seconds to wait before retrying, after a 429. */
    readonly retryAfter: number | null = null,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** The first message for a field, e.g. "email". */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

export const NETWORK_ERROR_MESSAGE = 'We couldn’t reach the restaurant. Check your connection and try again.';

type Method = 'GET' | 'POST' | 'PATCH' | 'DELETE';

interface RequestOptions<T> {
  method?: Method;
  body?: unknown;
  /** Validates and types the response body. Omit for 204 responses. */
  schema?: z.ZodType<T>;
  headers?: Record<string, string>;
  signal?: AbortSignal;
}

/**
 * The one way the app talks to the API. Adds the signed-in user's token (the auth
 * interceptor), turns error responses into ApiError, and signs the user out locally when
 * the API no longer accepts their token.
 */
export async function apiRequest<T = void>(path: string, options: RequestOptions<T> = {}): Promise<T> {
  const { method = 'GET', body, schema, headers = {}, signal } = options;
  const token = useSession.getState().token;

  let response: Response;

  try {
    response = await fetch(`${config.apiUrl}${path}`, {
      method,
      signal,
      headers: {
        Accept: 'application/json',
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...headers,
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch (error) {
    if (signal?.aborted) {
      throw error;
    }

    throw new ApiError(0, NETWORK_ERROR_MESSAGE);
  }

  if (response.status === 401 && token !== null && useSession.getState().token === token) {
    // The token was revoked or has expired: forget it so the app shows "Sign in".
    await useSession.getState().clear();
  }

  const payload: unknown = response.status === 204 ? undefined : await response.json().catch(() => undefined);

  if (!response.ok) {
    throw toApiError(response, payload);
  }

  return schema ? schema.parse(payload) : (payload as T);
}

function toApiError(response: Response, payload: unknown): ApiError {
  const body = (typeof payload === 'object' && payload !== null ? payload : {}) as {
    message?: unknown;
    errors?: unknown;
  };
  const retryAfter = Number(response.headers.get('Retry-After'));

  return new ApiError(
    response.status,
    typeof body.message === 'string' && body.message !== '' ? body.message : fallbackMessage(response.status),
    isFieldErrors(body.errors) ? body.errors : {},
    Number.isFinite(retryAfter) && retryAfter > 0 ? retryAfter : null,
  );
}

function isFieldErrors(value: unknown): value is Record<string, string[]> {
  return (
    typeof value === 'object' &&
    value !== null &&
    Object.values(value).every((messages) => Array.isArray(messages) && messages.every((m) => typeof m === 'string'))
  );
}

function fallbackMessage(status: number): string {
  if (status === 401) return 'Sign in to continue.';
  if (status === 403) return 'You don’t have access to that.';
  if (status === 404) return 'We couldn’t find that.';
  if (status === 429) return 'Too many attempts. Wait a minute and try again.';

  return 'Something went wrong on our side. Try again in a moment.';
}

/** A user-facing message for any error thrown by a query or mutation. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return 'Something went wrong. Try again in a moment.';
}

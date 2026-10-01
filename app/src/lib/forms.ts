import type { FieldValues, Path, UseFormSetError } from 'react-hook-form';

import { ApiError, errorMessage } from '@/lib/api/client';

/**
 * Puts the API's field errors (422) on the matching form fields, and anything else on the
 * form as a whole (`errors.root`).
 */
export function applyServerErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>, fields: Path<T>[]): void {
  if (error instanceof ApiError && error.status === 422) {
    let placed = false;

    for (const field of fields) {
      const message = error.fieldError(field);

      if (message) {
        setError(field, { type: 'server', message }, { shouldFocus: !placed });
        placed = true;
      }
    }

    if (placed) {
      return;
    }
  }

  setError('root', { type: 'server', message: errorMessage(error) });
}

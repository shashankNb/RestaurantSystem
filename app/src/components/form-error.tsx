import { CircleAlert } from 'lucide-react-native';

import { Alert, AlertDescription } from '@/components/ui/alert';

/** A problem with the whole form (not one field), such as "Those details didn't match". */
export function FormError({ message }: { message?: string }) {
  if (!message) {
    return null;
  }

  return (
    <Alert icon={CircleAlert} variant="destructive">
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  );
}

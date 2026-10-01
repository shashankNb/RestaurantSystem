import { Redirect } from 'expo-router';

/** /staff opens the order board (which sends anyone signed out to the sign-in). */
export default function StaffIndex() {
  return <Redirect href="/staff/orders" />;
}

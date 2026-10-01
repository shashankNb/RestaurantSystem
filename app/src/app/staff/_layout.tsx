import { Stack } from 'expo-router';

/** The kitchen screens: sign-in, then the order board and settings for signed-in staff. */
export default function StaffLayout() {
  return <Stack screenOptions={{ headerShown: false }} />;
}

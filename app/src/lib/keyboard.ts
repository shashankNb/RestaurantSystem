import { Platform } from 'react-native';

/**
 * Web: lets Space choose a Pressable that acts as a radio button or checkbox, as it would
 * a native control. React Native Web only does that for buttons (Enter works for all).
 * Spread the result onto the Pressable.
 */
export function spaceActivates(onPress: () => void, disabled = false): object {
  if (Platform.OS !== 'web' || disabled) {
    return {};
  }

  return {
    onKeyDown: (event: { key: string; preventDefault: () => void }) => {
      if (event.key === ' ' || event.key === 'Spacebar') {
        event.preventDefault();
        onPress();
      }
    },
  };
}

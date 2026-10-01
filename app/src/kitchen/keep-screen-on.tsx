import { useKeepAwake } from 'expo-keep-awake';

/** iOS and Android: keeps the screen on while mounted (during a shift). */
export function KeepScreenOn() {
  useKeepAwake('kitchen');

  return null;
}

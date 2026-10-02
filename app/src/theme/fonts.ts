// One weight per import: the packages' index files require every weight they have, which
// would all end up in the build.
import { Eczar_600SemiBold } from '@expo-google-fonts/eczar/600SemiBold';
import { Eczar_700Bold } from '@expo-google-fonts/eczar/700Bold';
import { Mukta_400Regular } from '@expo-google-fonts/mukta/400Regular';
import { Mukta_500Medium } from '@expo-google-fonts/mukta/500Medium';
import { Mukta_600SemiBold } from '@expo-google-fonts/mukta/600SemiBold';
import { Mukta_700Bold } from '@expo-google-fonts/mukta/700Bold';

/**
 * Eczar (display) and Mukta (everything else), both drawn for Devanagari as well as
 * Latin. Each weight is registered as its own family; global.css maps them to the
 * font-display… and font-body… classes.
 */
export const fonts = {
  Eczar_600SemiBold,
  Eczar_700Bold,
  Mukta_400Regular,
  Mukta_500Medium,
  Mukta_600SemiBold,
  Mukta_700Bold,
};

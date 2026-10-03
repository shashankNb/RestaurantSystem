// Square's In-App Payments SDK needs Kotlin 2.2.21 or later, and its config plugin pins the
// Kotlin Gradle plugin to that version. Expo's modules (and their Compose compiler) read the
// Kotlin version from android.kotlinVersion instead, which would stay at React Native's
// default. This sets it to the same version, so the whole Android build uses one Kotlin.
const { withGradleProperties } = require('expo/config-plugins');

module.exports = function withKotlinVersion(config, { version }) {
  return withGradleProperties(config, (gradle) => {
    gradle.modResults = gradle.modResults.filter((item) => !(item.type === 'property' && item.key === 'android.kotlinVersion'));
    gradle.modResults.push({ type: 'property', key: 'android.kotlinVersion', value: version });

    return gradle;
  });
};

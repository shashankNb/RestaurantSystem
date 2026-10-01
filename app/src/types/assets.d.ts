// Sound files bundled by Metro (the kitchen's new-order alert).
declare module '*.wav' {
  const source: import('expo-audio').AudioSource;
  export default source;
}

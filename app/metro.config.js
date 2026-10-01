const { getDefaultConfig } = require("expo/metro-config");
const { withMoeUI } = require("./moe-ui.metro.cjs");
const config = getDefaultConfig(__dirname);
module.exports = withMoeUI(config);

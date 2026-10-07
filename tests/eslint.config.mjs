export default [{
  files: ["**/*.js"],
  languageOptions: {
    ecmaVersion: 2020, sourceType: "script",
    globals: { window: "readonly", document: "readonly", self: "readonly", module: "writable", localStorage: "readonly",
      fetch: "readonly", DOMParser: "readonly", BigInt: "readonly", Promise: "readonly", setTimeout: "readonly", clearTimeout: "readonly",
      AbortController: "readonly", BroadcastChannel: "readonly", qrcode: "readonly" }
  },
  rules: { "no-undef": "error", "no-unused-vars": "error", "no-redeclare": "error", "eqeqeq": "error", "no-implied-eval": "error", "no-eval": "error" }
}];

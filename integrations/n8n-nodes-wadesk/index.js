// This package ships its nodes + credentials via the "n8n" field in
// package.json (pointing at the compiled files under dist/). n8n does not load
// anything from this entry point, but a valid "main" keeps npm/tooling happy.
module.exports = {};

/*
 * ── Global Node log gate + file logger ────────────────────────────────────
 * One switch for every console.log() in the helper service — no need to find
 * or delete the individual calls.
 *
 * It's imported FIRST in index.js / index-cpanel.js, so it also catches logs
 * emitted while the other modules are loading.
 *
 * TWO jobs:
 *   1. FILE LOGGER (always on): every console.log/info/warn/error/debug line
 *      is ALSO written to  node/node.log  with an ISO timestamp + level, so we
 *      can pull one file to trace the bridge (JID resolution, broadcast route,
 *      campaign sends, etc.) exactly like laravel.log. The original console
 *      still prints, so pm2 logs keep working. The file auto-rotates at ~20 MB
 *      (previous kept as node.log.1) and logging never throws — a write error
 *      is swallowed so it can never crash the service.
 *      Override the path with  WADESK_NODE_LOG=/abs/path.log  if needed.
 *
 *   2. CONSOLE SILENCER (optional): silence console.log/.info/.debug in
 *      production while keeping .warn/.error. Currently DISABLED for
 *      debugging — the FILE still captures everything regardless.
 *      To re-enable quiet console: set  WADESK_LOGS=on  is NOT needed; instead
 *      un-comment the silencer block at the bottom (it still writes to file).
 */

import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const LOG_FILE = process.env.WADESK_NODE_LOG || path.join(__dirname, "node.log");
const MAX_BYTES = 20 * 1024 * 1024; // rotate to node.log.1 past ~20 MB

// Keep the REAL console methods before we wrap them.
const _orig = {
  log: console.log.bind(console),
  info: console.info.bind(console),
  warn: console.warn.bind(console),
  error: console.error.bind(console),
  debug: console.debug.bind(console),
};

let _stream = null;
let _bytes = 0;

function _open() {
  try {
    try { _bytes = fs.statSync(LOG_FILE).size; } catch { _bytes = 0; }
    _stream = fs.createWriteStream(LOG_FILE, { flags: "a" });
    _stream.on("error", () => { _stream = null; }); // disk full / perms → give up quietly
  } catch { _stream = null; }
}

function _rotate() {
  try { if (_stream) _stream.end(); } catch { /* ignore */ }
  try { fs.renameSync(LOG_FILE, LOG_FILE + ".1"); } catch { /* ignore */ }
  _bytes = 0;
  _open();
}

function _fmt(args) {
  return args
    .map((a) => {
      if (typeof a === "string") return a;
      if (a instanceof Error) return a.stack || a.message;
      try { return JSON.stringify(a); } catch { return String(a); }
    })
    .join(" ");
}

function _write(level, args) {
  try {
    if (!_stream) return;
    const line = `[${new Date().toISOString()}] ${level}: ${_fmt(args)}\n`;
    _stream.write(line);
    _bytes += Buffer.byteLength(line);
    if (_bytes > MAX_BYTES) _rotate();
  } catch { /* logging must never crash the app */ }
}

_open();

// Tee every level to the file, then print with the original method so pm2 /
// terminal output is unchanged.
console.log   = (...a) => { _write("LOG",   a); _orig.log(...a); };
console.info  = (...a) => { _write("INFO",  a); _orig.info(...a); };
console.warn  = (...a) => { _write("WARN",  a); _orig.warn(...a); };
console.error = (...a) => { _write("ERROR", a); _orig.error(...a); };
console.debug = (...a) => { _write("DEBUG", a); _orig.debug(...a); };

// Uncaught failures should always land in the file.
process.on("uncaughtException", (e) => { _write("FATAL", ["uncaughtException", e]); _orig.error(e); });
process.on("unhandledRejection", (e) => { _write("FATAL", ["unhandledRejection", e]); _orig.error(e); });

_orig.log(`[log-gate] node.log file logging active -> ${LOG_FILE}`);

/*
 * ── Optional quiet-console silencer ───────────────────────────────────────
 * Un-comment to stop console.log/.info/.debug PRINTING in production. The file
 * logger above still records every level, so node.log stays complete.
 *
 * if (process.env.WADESK_LOGS !== "on") {
 *   console.log   = (...a) => { _write("LOG",   a); };
 *   console.info  = (...a) => { _write("INFO",  a); };
 *   console.debug = (...a) => { _write("DEBUG", a); };
 * }
 */

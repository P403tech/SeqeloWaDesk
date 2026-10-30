/**
 * Auth mode and browser-origin allow-list for the Node bridge.
 *
 * Same-host installs (cPanel, local) keep the loopback bypass so Laravel
 * can call 127.0.0.1 without a header. A Railway service is on the public
 * internet, so the default there is the shared token.
 */

export function resolveApiAuthMode() {
  const onRailway = Boolean(process.env.RAILWAY_ENVIRONMENT);
  const fallback = onRailway ? "token" : "loopback";
  const raw = String(process.env.NODE_API_AUTH || fallback).toLowerCase().trim();

  if (raw === "off") {
    console.warn("[auth] NODE_API_AUTH=off — the /api gate is disabled. Remove it once Laravel sends X-Node-Token.");
  }

  if (raw !== "token" && raw !== "loopback" && raw !== "off") {
    return fallback;
  }

  return raw;
}

function asOrigin(value) {
  const v = String(value || "").trim().replace(/\/+$/, "");
  if (!v) return "";
  if (/^https?:\/\//i.test(v)) return v;
  return "https://" + v;
}

/**
 * Allow no-origin calls (Laravel, curl), browser extensions, and localhost.
 * When the app has a real public host, every other browser origin is refused
 * unless it is listed in ALLOWED_ORIGINS. ALLOWED_ORIGINS=* keeps the old
 * allow-all behaviour.
 */
export function makeCorsOriginCheck(appDomainName) {
  const listed = String(process.env.ALLOWED_ORIGINS || "")
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean);
  const allowAll = listed.includes("*");
  const appOrigin = asOrigin(appDomainName);
  const appIsLocal = appOrigin === ""
    || appOrigin === "localhost"
    || /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/i.test(appOrigin);

  const restrict = !allowAll && (listed.length > 0 || !appIsLocal);

  const allowed = new Set(
    [appOrigin, process.env.APP_URL, process.env.RAILWAY_PUBLIC_DOMAIN, process.env.RAILWAY_STATIC_URL, ...listed]
      .map(asOrigin)
      .filter((o) => o && o !== "https://*")
  );

  return function corsOriginCheck(origin, cb) {
    if (!origin) return cb(null, true);
    const o = String(origin).replace(/\/+$/, "");
    if (o.startsWith("chrome-extension://") || o.startsWith("moz-extension://")) return cb(null, true);
    if (/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/i.test(o)) return cb(null, true);
    if (!restrict) return cb(null, true);
    if (allowed.has(o)) return cb(null, true);
    console.warn(`[cors] blocked origin: ${origin}`);
    return cb(null, false);
  };
}

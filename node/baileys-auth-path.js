import path from "path";
import { fileURLToPath } from "url";

const here = path.dirname(fileURLToPath(import.meta.url));

/**
 * Directory that holds session_<phone> folders. Defaults to node/baileys_auth.
 * Set BAILEYS_AUTH_DIR to a mounted volume (the Railway image uses /data/baileys)
 * so a redeploy does not unlink every paired phone.
 */
export function baileysAuthRoot() {
  const fromEnv = String(process.env.BAILEYS_AUTH_DIR || "").trim();
  if (fromEnv) return path.resolve(fromEnv);
  return path.join(here, "baileys_auth");
}

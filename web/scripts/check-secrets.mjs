// Fails if a real secret ends up in the built site: a Supabase secret key, a private key, or any JWT whose role
// is not "anon" (for example the service_role key). Library code that merely mentions these words is fine.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const files = [];
(function walk(d) { for (const f of readdirSync(d)) { const p = join(d, f); statSync(p).isDirectory() ? walk(p) : files.push(p); } })('dist');
const problems = [];
for (const f of files) {
  const text = readFileSync(f, 'utf8');
  if (/sb_secret_[A-Za-z0-9_-]{16,}/.test(text)) problems.push(`${f}: Supabase secret key`);
  if (/-----BEGIN [A-Z ]*PRIVATE KEY-----/.test(text)) problems.push(`${f}: private key`);
  for (const jwt of text.match(/eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/g) ?? []) {
    try {
      const role = JSON.parse(Buffer.from(jwt.split('.')[1], 'base64url').toString()).role;
      if (role !== 'anon') problems.push(`${f}: JWT with role "${role}"`);
    } catch { /* not a JWT */ }
  }
}
if (problems.length) { console.error('Secrets found in the build:\n' + problems.join('\n')); process.exit(1); }
console.log(`No secrets in ${files.length} built files.`);

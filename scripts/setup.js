import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const envPath = fileURLToPath(new URL('../.env', import.meta.url));
const example = readFileSync(
  new URL('../.env.example', import.meta.url),
  'utf8',
);
const existing = existsSync(envPath) ? readFileSync(envPath, 'utf8') : '';
const lines = existing
  .split(/\r?\n/)
  .filter((line) => !line.startsWith('DATABASE_PATH=') && line.trim());
const keys = new Set(lines.map((line) => line.split('=')[0].trim()));
for (const line of example.split(/\r?\n/).filter(Boolean)) {
  const key = line.split('=')[0];
  if (!keys.has(key)) {
    lines.push(
      key === 'ADMIN_TOKEN'
        ? `ADMIN_TOKEN=${randomBytes(32).toString('hex')}`
        : line,
    );
  }
}
writeFileSync(envPath, lines.join('\n') + '\n', { mode: 0o600 });
console.log('.env siap. Nilai konfigurasi yang sudah ada tetap dipertahankan.');

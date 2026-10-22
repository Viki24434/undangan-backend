import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { parseOrigins } from './cors.js';

const envFile = fileURLToPath(new URL('../.env', import.meta.url));
if (existsSync(envFile)) process.loadEnvFile(envFile);

function positiveInteger(value, fallback, name, maximum = 65535) {
  const number = Number(value ?? fallback);
  if (!Number.isInteger(number) || number < 1 || number > maximum)
    throw new Error(`${name} tidak valid.`);
  return number;
}

export const databaseConfig = {
  host: process.env.DB_HOST || '127.0.0.1',
  port: positiveInteger(process.env.DB_PORT, 3306, 'DB_PORT'),
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD ?? '',
  database: process.env.DB_NAME || 'undangan_db',
  connectionLimit: positiveInteger(
    process.env.DB_CONNECTION_LIMIT,
    10,
    'DB_CONNECTION_LIMIT',
    100,
  ),
};

if (!/^[a-zA-Z0-9_]{1,64}$/.test(databaseConfig.database))
  throw new Error('DB_NAME hanya boleh berisi huruf, angka, dan underscore.');

export const serverConfig = {
  host: process.env.HOST || '127.0.0.1',
  port: positiveInteger(process.env.PORT, 3001, 'PORT'),
  adminToken: process.env.ADMIN_TOKEN,
  secureCookie: process.env.COOKIE_SECURE === 'true',
  origins: parseOrigins(process.env.CORS_ORIGINS),
};

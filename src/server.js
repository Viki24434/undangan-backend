import { createServer } from 'node:http';
import { openDatabase } from './database.js';
import { createApp } from './app.js';
import { databaseConfig, serverConfig } from './config.js';

const { host, port, adminToken, origins, secureCookie } = serverConfig;
if (
  !adminToken ||
  adminToken.length < 32 ||
  adminToken.startsWith('replace-with-')
) {
  throw new Error(
    'Jalankan npm run setup atau isi ADMIN_TOKEN acak minimal 32 karakter.',
  );
}
const db = await openDatabase(databaseConfig);
const server = createServer(
  createApp({ db, adminToken, origins, secureCookie }),
);
server.requestTimeout = 15000;
server.headersTimeout = 10000;
server.on('error', async (error) => {
  console.error(`Server gagal berjalan: ${error.message}`);
  await db.close();
  process.exitCode = 1;
});
server.listen(port, host, () =>
  console.log(
    `Undangan API tersedia di http://${host}:${port}/api | MySQL: ${databaseConfig.database}`,
  ),
);
let stopping = false;
function stop() {
  if (stopping) return;
  stopping = true;
  server.close(async () => {
    await db.close();
  });
  server.closeIdleConnections();
}
process.on('SIGINT', stop);
process.on('SIGTERM', stop);

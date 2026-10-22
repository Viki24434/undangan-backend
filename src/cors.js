export function parseOrigins(value) {
  if (typeof value !== 'string' && !Array.isArray(value))
    throw new Error(
      'Isi CORS_ORIGINS pada .env backend sebelum menjalankan server.',
    );
  const entries = Array.isArray(value) ? value : value.split(',');
  const origins = entries
    .map((entry) => entry.trim())
    .filter(Boolean)
    .map((entry) => {
      let url;
      try {
        url = new URL(entry);
      } catch {
        throw new Error(
          'CORS_ORIGINS harus berisi alamat HTTP/HTTPS yang valid.',
        );
      }
      if (
        !['http:', 'https:'].includes(url.protocol) ||
        url.username ||
        url.password
      )
        throw new Error(
          'CORS_ORIGINS harus berisi alamat HTTP/HTTPS tanpa kredensial.',
        );
      return url.origin;
    });
  if (!origins.length)
    throw new Error(
      'Isi CORS_ORIGINS pada .env backend sebelum menjalankan server.',
    );
  return [...new Set(origins)];
}

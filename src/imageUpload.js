import { HttpError } from './validation.js';

export const imageFormats = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
};

export async function readImage(req) {
  const mime = req.headers['content-type']?.split(';')[0].trim().toLowerCase();
  const extension = imageFormats[mime];
  if (!extension) throw new HttpError(415, 'Gunakan JPG, PNG, atau WebP.');
  let size = 0;
  const chunks = [];
  for await (const chunk of req) {
    size += chunk.length;
    if (size > 5 * 1024 * 1024)
      throw new HttpError(413, 'File upload maksimal 5 MB setelah diproses.');
    chunks.push(chunk);
  }
  const buffer = Buffer.concat(chunks);
  const valid =
    extension === 'jpg'
      ? buffer.subarray(0, 3).equals(Buffer.from([255, 216, 255]))
      : extension === 'png'
        ? buffer
            .subarray(0, 8)
            .equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]))
        : buffer.toString('ascii', 0, 4) === 'RIFF' &&
          buffer.toString('ascii', 8, 12) === 'WEBP';
  if (!valid) throw new HttpError(422, 'Isi file bukan gambar yang valid.');
  return { buffer, extension, mime };
}

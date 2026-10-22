# Undangan Backend

API JavaScript menggunakan Node.js 24+, HTTP bawaan Node, serta MySQL/MariaDB XAMPP melalui mysql2. Aktifkan MySQL di XAMPP sebelum menjalankan backend.

## Menjalankan

```powershell
cd C:\xampp\htdocs\undangan-backend
npm install
npm run setup
npm start
```

API berjalan pada `http://127.0.0.1:3001/api`. Gunakan `npm run dev` untuk watch mode. `npm run setup` membuat `.env` dengan token admin acak dan melengkapi konfigurasi MySQL tanpa mengganti nilai yang sudah ada. Database MySQL dibuat otomatis sesuai DB_NAME dan 10 template frontend dimasukkan otomatis. Semua undangan baru berstatus draft sampai dipublikasikan.

Backend berjalan sebagai proses Node tersendiri, bukan melalui URL Apache `/undangan-backend`. Jangan sajikan folder backend melalui Apache: `.env` dan database merupakan data privat. `.htaccess` di folder ini menolak akses langsung dari Apache. Server hanya mendengarkan localhost secara default.

## Konfigurasi

Default lokal XAMPP di `.env`:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=root
DB_PASSWORD=
DB_NAME=undangan_db
DB_CONNECTION_LIMIT=10
```

Sesuaikan password jika root MySQL memakai password. Database dan tabel InnoDB dibuat otomatis ketika server dimulai; akun database memerlukan izin CREATE. Karakter Unicode menggunakan utf8mb4. Untuk produksi gunakan akun database khusus. Konfigurasi `.env` dimuat dengan path absolut dari folder backend; environment proses memiliki prioritas.

Lihat `.env.example`. `CORS_ORIGINS` berisi origin frontend yang diizinkan, dipisahkan koma. `ADMIN_TOKEN` minimal 32 karakter; jangan taruh token ini dalam kode frontend atau variabel `VITE_*`. Pengelolaan admin dilakukan oleh klien tepercaya menggunakan `Authorization: Bearer <ADMIN_TOKEN>`. Dashboard menggunakan akun admin dan session cookie HttpOnly. Bearer token tetap tersedia untuk klien server atau integrasi tepercaya.

Daftar origin hanya berasal dari `CORS_ORIGINS` pada `.env` atau environment proses. Variabel ini wajib diisi; server tidak menggunakan daftar cadangan di kode. Frontend lokal memakai port `2125`; preview produksi memakai `4173`. Pastikan alamat yang dipakai browser tercantum, misalnya `http://127.0.0.1:2125`. `.env.example` menyediakan contoh yang dapat disesuaikan. Origin mencakup protokol, host, dan port. Alamat dinormalisasi sehingga garis miring di akhir tidak menyebabkan penolakan. Untuk domain atau tunnel baru, tambahkan origin HTTPS sebenarnya ke `CORS_ORIGINS` dan mulai ulang backend.

Pemeriksaan origin juga berlaku sebelum admin pertama dibuat. Jika halaman admin terbuka tetapi pendaftaran ditolak dengan pesan origin, periksa alamat browser dan daftar `CORS_ORIGINS`; database tidak perlu dihapus atau dibuat ulang.

## Endpoint publik

| Metode | Endpoint                        | Fungsi                                                  |
| ------ | ------------------------------- | ------------------------------------------------------- |
| GET    | `/api/health`                   | Status API dan database                                 |
| GET    | `/api/social-links`             | Tautan sosial publik                                    |
| GET    | `/api/templates`                | Daftar template; filter opsional `?category=Pernikahan` |
| GET    | `/api/invitations/:slug`        | Undangan published                                      |
| GET    | `/api/invitations/:slug/wishes` | Ucapan, `limit` 1–100 dan `offset` ≥ 0                  |
| POST   | `/api/invitations/:slug/wishes` | Simpan `{ "name": "Rina", "message": "Selamat!" }`      |
| POST   | `/api/invitations/:slug/rsvps`  | Simpan konfirmasi kehadiran                             |

RSVP: `{ "name": "Rina", "attendance": "Hadir", "guestCount": 2, "note": "Bersama keluarga" }`. `attendance`: `Hadir`, `Belum pasti`, atau `Tidak hadir`. `guestCount`: 0–20; Hadir minimal 1, Tidak hadir harus 0. Data RSVP hanya dapat dibaca admin. Pengiriman RSVP bersifat append; belum ada autentikasi tamu atau penggantian RSVP sebelumnya.

## Endpoint admin

| Metode               | Endpoint                            | Fungsi                 |
| -------------------- | ----------------------------------- | ---------------------- |
| GET / POST           | `/api/admin/invitations`            | Daftar / buat undangan |
| GET / PATCH / DELETE | `/api/admin/invitations/:id`        | Detail / ubah / hapus  |
| GET                  | `/api/admin/invitations/:id/rsvps`  | Daftar RSVP privat     |
| GET                  | `/api/admin/invitations/:id/wishes` | Daftar ucapan          |
| DELETE               | `/api/admin/wishes/:id`             | Hapus ucapan           |
| PATCH                | `/api/admin/social-links`           | Ubah tautan sosial global |

Daftar admin juga mendukung `limit` dan `offset`. Menghapus undangan ikut menghapus semua RSVP dan ucapan terkait. PATCH mengganti keseluruhan `data` jika field tersebut dikirim.

Tautan sosial menerima `instagram` dan `tiktok` berupa URL profil HTTPS, serta
`whatsapp` berupa 8–15 digit nomor dengan kode negara tanpa tanda `+`.

Contoh payload membuat undangan:

```json
{
  "slug": "aditya-sarah",
  "templateId": "elegant-gold-01",
  "title": "Aditya & Sarah",
  "eventDate": "2027-01-24T09:00:00+07:00",
  "status": "published",
  "data": {
    "groom": { "name": "Aditya" },
    "bride": { "name": "Sarah" }
  }
}
```

Contoh PowerShell, token dibaca dari file dan tidak dicetak:

```powershell
$tokenLine = Get-Content .env | Where-Object { $_ -like 'ADMIN_TOKEN=*' }
$adminToken = $tokenLine.Substring('ADMIN_TOKEN='.Length)
$headers = @{ Authorization = "Bearer $adminToken" }
$payload = @{
  slug = 'aditya-sarah'
  templateId = 'elegant-gold-01'
  title = 'Aditya & Sarah'
  eventDate = '2027-01-24T09:00:00+07:00'
  status = 'published'
  data = @{ groom = @{ name = 'Aditya' }; bride = @{ name = 'Sarah' } }
} | ConvertTo-Json -Depth 10
Invoke-RestMethod -Method Post -Uri 'http://127.0.0.1:3001/api/admin/invitations' -Headers $headers -ContentType 'application/json' -Body $payload
```

Respons sukses: `{ "success": true, "data": ... }`, daftar berpaginasi juga memuat `meta`. Respons gagal: `{ "success": false, "message": "..." }`. Body dibatasi 32 KB, kiriman publik dibatasi 20 per menit per alamat IP. Tanpa reverse proxy, IP diambil langsung dari koneksi. Request JSON wajib menggunakan `Content-Type: application/json`.

## Integrasi frontend

### Daftar tamu

Di dashboard, buka **Daftar tamu** pada undangan yang ingin dikelola. Tambahkan nama secara manual atau unduh contoh Excel, isi sheet `Daftar Tamu`, lalu impor dan periksa pratinjaunya. Kolomnya `Nama Tamu`, `No. WhatsApp`, `Kelompok`, dan `Catatan`. Hanya nama yang wajib diisi. Nomor WhatsApp menggunakan format Text agar angka 0 di depan tetap tersimpan.

Klik **Simpan daftar tamu** setelah mengubah daftar. Ekspor Excel mencakup semua nama, status kirim manual, dan tautan pribadi `?to=...` untuk undangan yang sudah terbit. WhatsApp membuka pesan yang siap ditinjau dan dikirim oleh pengguna; status kirim ditandai manual. Nama yang sama dilewati saat impor; tambahkan pembeda jika orangnya berbeda. Draf tetap dapat memiliki daftar tamu, tetapi tautan baru dapat dibagikan setelah undangan diterbitkan.

Bagian **Pesan WhatsApp** dapat diedit per undangan, termasuk sapaan dan salam sesuai kebutuhan. Pesan bawaan netral; contoh Islami tersedia sebagai pilihan. Gunakan tag `{nama_tamu}`, `{judul_undangan}`, dan `{link_undangan}` untuk mengisi data secara otomatis. Tag link wajib disertakan, panjang pesan maksimal 6.000 karakter. Pratinjau menggunakan contoh nama yang dapat diubah tanpa mengubah daftar tamu. Simpan melalui **Simpan daftar tamu**.

Backend menyediakan GET dan PATCH `/api/admin/invitations/:id/guest-list`, keduanya memerlukan autentikasi admin. PATCH menerima `{ "revision": 0, "guests": [] }`; setiap tamu berisi `id`, `name`, `phone`, `group`, `note`, dan `sent`. Gunakan revisi terakhir dari GET. Konflik revisi mengembalikan 409 agar perubahan dari tab lain tidak tertimpa.

GET juga mengembalikan `messageTemplate`; nilai kosong berarti gunakan pesan bawaan frontend. PATCH dapat menyertakan `messageTemplate` berupa teks dengan tag link. Jika field dihilangkan, pesan yang sudah tersimpan dipertahankan. Dokumen baru disimpan dalam bentuk `{ "guests": [], "messageTemplate": "..." }` pada baris yang sama. Dokumen lama berbentuk array tamu tetap dapat dibaca dan diperbarui tanpa migrasi tabel.

Seluruh daftar disimpan sebagai satu dokumen JSON dalam satu baris tabel `invitation_guest_lists` per undangan, dibuat saat daftar pertama disimpan. Tabel dibuat otomatis ketika backend dimulai. Data ini tidak disertakan dalam endpoint undangan publik atau daftar undangan. Batasnya 5.000 nama dan body 2 MB khusus endpoint ini; batas endpoint JSON lainnya tetap 32 KB. Impor/ekspor Excel diproses di browser melalui worker, file Excel tidak diunggah ke server. Daftar ikut terhapus ketika undangan dihapus.

Frontend sudah terhubung melalui VITE_API_URL. Home dan katalog memuat metadata dari endpoint templates. Route /t/:id menampilkan demo, sedangkan /u/:slug memuat undangan published dari database. Form RSVP dan ucapan pada undangan nyata mengirim data ke API dan daftar ucapan dimuat kembali dari database. Token admin tidak digunakan atau disimpan di frontend. Jika backend tidak tersedia, katalog menampilkan koleksi demo dengan keterangan.

Backup database MySQL melalui phpMyAdmin atau mysqldump. Untuk deployment, gunakan HTTPS melalui reverse proxy, konfigurasi origin frontend sebenarnya, serta pengelolaan akun admin dan proteksi spam yang sesuai kebutuhan produksi.

## Struktur kode

- `src/models`: TemplateModel, InvitationModel, WishModel, dan RsvpModel mengelola query database.
- `src/controllers`: validasi dan proses API untuk masing-masing data.
- `src/routes.js`: pemetaan endpoint ke controller.
- `src/app.js`: HTTP, CORS, autentikasi admin, pembatasan request, dan respons error.
- `src/database.js`: koneksi MySQL, inisialisasi schema, dan seed template.

Undangan nyata dibuka di frontend menggunakan `/u/<slug>`, misalnya `http://localhost:5173/u/aditya-sarah`, setelah undangan dibuat dan berstatus published. Field `data` dapat berisi profil mempelai, foto, cerita, jadwal, serta informasi acara sesuai template. `eventDate` mengatur tanggal dan countdown; jadwal khusus dapat dikirim lewat `data.events`. Jangan membagikan route demo sebagai undangan nyata.

## Login dashboard

- GET `/api/auth/session`: status login, profil admin, token CSRF, dan apakah akun pertama perlu dibuat.
- POST `/api/auth/setup`: buat akun pertama dengan `{ "name": "Admin", "email": "admin@example.com", "password": "password-minimal-12-karakter" }`. Hanya tersedia sebelum akun admin pertama dibuat.
- POST `/api/auth/login`: email dan password.
- POST `/api/auth/logout`: keluar dan hapus session.

Password disimpan sebagai hash scrypt dengan salt acak. Token session acak disimpan sebagai hash SHA-256 di MySQL dan kedaluwarsa setelah 8 jam. Cookie memakai HttpOnly, SameSite=Lax, dan Path=/api. Permintaan admin yang mengubah data melalui cookie wajib membawa X-CSRF-Token dari respons session/login. Bearer token API tetap didukung untuk integrasi non-browser.

`COOKIE_SECURE=false` untuk HTTP XAMPP lokal; gunakan `true` dengan HTTPS pada deployment. Buat akun admin pertama sebelum membuka server kepada publik. Frontend menggunakan proxy Vite `/api` ke backend agar host cookie sama. Tabel admins dan admin_sessions dibuat otomatis saat backend dimulai, tanpa menghapus tabel undangan sebelumnya.

## Foto tamu untuk Platinum

Undangan published dengan paket template `platinum` menyediakan kirim foto setelah waktu `eventDate` tercapai. Waktu mulai diperiksa lagi oleh backend. Nama tamu diambil frontend dari `?to=Viki`; jika kosong, tamu mengisi nama sendiri.

- POST `/api/invitations/:slug/photos?to=Viki`: body berupa file biner JPG/PNG/WebP, dengan Content-Type sesuai gambar, maksimal 5 MB. Frontend mengecilkan foto sebelum mengirim dan mengunggah satu per satu, maksimal 10 foto setiap pengiriman.
- GET `/api/admin/invitations/:id/photos`: daftar foto dengan pagination, memerlukan login admin.
- GET `/api/admin/invitations/:id/photos/:photoId`: unduh foto, memerlukan login admin.

File disimpan di `data/guest-photos/`, dengan pola `nama-tamu-YYYY-MM-DD-UUID.jpg` sesuai format hasil unggahan. Nama pengirim hanya disertakan pada nama file, tanpa tulisan pada gambar. Metadata disimpan dalam tabel MySQL `guest_photos`, yang dibuat otomatis saat backend dimulai. Foto tersedia di bagian **Foto dari tamu** pada editor undangan admin. Foto tidak otomatis masuk ke galeri publik. Menghapus undangan juga menghapus file foto tamunya. Backup folder ini bersama database.

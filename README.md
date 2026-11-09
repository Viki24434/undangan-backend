# Undangan Back — PHP

Backend undangan digital memakai PHP 8.2+, PDO, dan MySQL/MariaDB XAMPP. Tidak membutuhkan Node.js, npm, Composer, atau framework. Frontend React tetap berada di `undangan-app`.

## Menjalankan dengan XAMPP

1. Aktifkan **Apache** dan **MySQL** di XAMPP.
2. Buka terminal di `C:\xampp\htdocs\undangan-back`.
3. Jalankan:

```powershell
php bin/setup.php
```

Setup membuat `.env` jika belum ada, database, tabel, dan template bawaan. Setup dapat dijalankan kembali: undangan, admin, harga, daftar tamu, template custom, serta desain yang sudah diubah tetap tersimpan. Database lama dari versi Node.js memakai tabel yang sama dan tidak perlu diekspor atau dihapus. File unggahan tetap berada di folder `data` yang ikut dipindahkan.

API XAMPP tersedia di:

```text
http://127.0.0.1/undangan-back/public/api/health
```

Pada `.env` frontend:

```dotenv
VITE_API_URL=/api
BACKEND_URL=http://127.0.0.1/undangan-back/public
```

Jalankan `npm run dev` di frontend, lalu buka `http://localhost:2125`. Restart Vite setelah mengganti `.env`. Frontend meneruskan `/api` ke PHP sehingga cookie admin dan URL gambar `/api/media/...` tetap bekerja pada origin yang sama.

## Alternatif server PHP lokal

Jika tidak memakai Apache untuk API:

```powershell
php -S 127.0.0.1:3001 -t public router.php
```

Ubah `.env` frontend menjadi `BACKEND_URL=http://127.0.0.1:3001`. MySQL XAMPP tetap perlu aktif. Server bawaan PHP hanya untuk pengembangan.

## Struktur folder

```text
undangan-back/
├── app/
│   ├── Controllers/       Alur endpoint dan respons
│   ├── Core/              Database PDO, router, request, response, .env
│   ├── Models/            Query database
│   ├── Services/          Upload, file, CORS, password, setup, rate limit
│   └── Validation/        Validasi undangan, tamu, desain, tema, kiriman
├── bin/setup.php          Setup database melalui terminal
├── config/app.php         Konfigurasi dari .env
├── database/
│   ├── schema.sql         Struktur tabel
│   └── seeds/             Metadata dan data template bawaan
├── data/                  Unggahan dan gambar privat, tidak masuk Git
├── public/index.php       Pintu masuk HTTP
├── routes/api.php         Seluruh daftar endpoint
├── storage/               Rate limit dan penyimpanan operasional
├── bootstrap.php          Autoload dan pemuatan konfigurasi
└── router.php             Router server pengembangan PHP
```

Untuk mengubah alur fitur, mulai dari `routes/api.php`, lanjut ke controller terkait, lalu model untuk query SQL. Validasi dipisahkan agar controller tetap mudah dibaca. Kode ditulis tanpa komentar inline.

## Konfigurasi .env

```dotenv
CORS_ORIGINS=http://localhost:2125,http://127.0.0.1:2125,http://localhost:4173,http://127.0.0.1:4173
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=root
DB_PASSWORD=
DB_NAME=undangan_db
COOKIE_SECURE=false
COOKIE_PATH=/
```

Origin hanya berasal dari `CORS_ORIGINS`; tidak ada daftar origin cadangan di kode. Tambahkan domain/tunnel frontend yang sebenarnya, lengkap dengan protokol dan port jika digunakan. Pemeriksaan origin berlaku juga saat pendaftaran admin pertama. Konfigurasi dibaca pada setiap request PHP. Environment proses memiliki prioritas atas `.env`; restart proses PHP jika environment proses diubah.

`ADMIN_TOKEN` bersifat opsional untuk integrasi server tepercaya dan harus acak minimal 32 karakter jika digunakan. Setup menghasilkan token ketika membuat `.env` baru. Dashboard menggunakan akun admin, bukan token ini. Jangan memasukkan token ke `VITE_*` atau kode frontend.

Path penyimpanan dapat diatur dengan `STORAGE_PATH`, `MEDIA_PATH`, `GUEST_PHOTOS_PATH`, dan `SUBMISSIONS_PATH`; gunakan path absolut. Default mengikuti folder proyek sehingga pemindahan proyek tidak membutuhkan path hardcode.

Aktifkan extension `pdo_mysql`, `mbstring`, dan `intl` di PHP. Akun database memerlukan izin CREATE/ALTER untuk setup; request biasa hanya memakai koneksi database yang sudah disiapkan. MySQL memakai utf8mb4 dan prepared statements.

## Autentikasi

| Metode | Endpoint | Fungsi |
| --- | --- | --- |
| GET | `/api/auth/session` | Status akun, session, dan token CSRF |
| POST | `/api/auth/setup` | Membuat admin pertama: `name`, `email`, `password` |
| POST | `/api/auth/login` | Login: `email`, `password` |
| POST | `/api/auth/logout` | Logout session |

Password 12–128 karakter. Password baru diproses melalui SHA-256 lalu `password_hash` PHP agar seluruh panjang password masuk ke verifikasi bcrypt. Login memakai `password_verify`. Akun lama berformat scrypt tetap dapat login dengan password yang sama; setelah login berhasil hash otomatis ditingkatkan ke format PHP. Kompatibilitas scrypt hanya untuk migrasi dan ditulis dalam PHP berdasarkan [RFC 7914](https://www.rfc-editor.org/rfc/rfc7914); tidak menjalankan Node.js. API password PHP dijelaskan pada [dokumentasi PHP](https://www.php.net/manual/en/function.password-hash.php).

Session disimpan di MySQL, cookie HttpOnly/SameSite=Lax berlaku 8 jam. Mutasi admin melalui cookie wajib menyertakan `X-CSRF-Token` dari endpoint session. Integrasi server dapat menggunakan `Authorization: Bearer <ADMIN_TOKEN>`. Session versi lama tetap memakai tabel yang sama. Cookie lama pada path `/api` dibersihkan saat login/logout baru.

## Endpoint publik

| Metode | Endpoint | Fungsi |
| --- | --- | --- |
| GET | `/api/health` | Koneksi API dan MySQL |
| GET | `/api/packages` | Paket dan harga; Exclusive selalu custom |
| GET | `/api/social-links` | Instagram, TikTok, WhatsApp |
| GET | `/api/templates` | Koleksi; filter `?category=Pernikahan` |
| GET | `/api/templates/:id` | Data template lengkap |
| GET | `/api/media/:filename` | Gambar unggahan publik |
| GET | `/api/invitations/:slug` | Undangan published |
| GET / POST | `/api/invitations/:slug/wishes` | Baca / simpan ucapan |
| POST | `/api/invitations/:slug/rsvps` | Konfirmasi kehadiran |
| POST | `/api/invitations/:slug/photos?to=Nama` | Foto tamu Platinum setelah acara dimulai |

Ucapan menerima `{ "name": "Viki", "message": "Selamat!" }`. Nama yang sama setelah normalisasi memperbarui ucapan sebelumnya. RSVP menerima `name`, `attendance` (`Hadir`, `Belum pasti`, `Tidak hadir`), `guestCount` 0–20, serta `note` opsional; pengiriman berikutnya memperbarui data tamu tersebut. Hadir minimal satu orang, Tidak hadir harus nol. Data RSVP dan foto tamu hanya dapat dibaca admin. Nama pengirim foto disertakan pada nama file.

## Endpoint admin

Semua endpoint berikut memerlukan session admin atau bearer token tepercaya.

| Metode | Endpoint | Fungsi |
| --- | --- | --- |
| PATCH | `/api/admin/packages/:id` | Ubah `price` Silver/Gold/Platinum |
| PATCH | `/api/admin/social-links` | Ubah tautan sosial |
| POST | `/api/admin/templates` | Salin template menjadi template custom |
| PATCH | `/api/admin/templates/:id` | Ubah `packageId` atau `data` |
| POST | `/api/admin/uploads` | Upload satu gambar |
| GET / POST | `/api/admin/invitations` | Daftar / buat undangan |
| GET / PATCH / DELETE | `/api/admin/invitations/:id` | Detail / edit / hapus undangan |
| GET / PATCH | `/api/admin/invitations/:id/guest-list` | Daftar tamu dan pesan custom |
| GET | `/api/admin/invitations/:id/wishes` | Ucapan undangan |
| GET | `/api/admin/invitations/:id/rsvps` | Data kehadiran |
| GET | `/api/admin/invitations/:id/photos` | Daftar foto tamu |
| GET | `/api/admin/invitations/:id/photos/:photoId` | Unduh foto tamu |
| DELETE | `/api/admin/wishes/:id` | Hapus ucapan |

Contoh membuat draft:

```json
{
  "slug": "adrian-clara",
  "templateId": "elegant-gold-01",
  "title": "Adrian & Clara",
  "eventDate": "2027-12-15T09:00:00+07:00",
  "status": "draft",
  "data": {
    "groom": { "name": "Adrian" },
    "bride": { "name": "Clara" },
    "photos": []
  }
}
```

Terbitkan dengan PATCH `{ "status": "published" }`. URL publik tetap 404 selama draft. PATCH `data` mengganti isi data, sedangkan pengaturan desain/tema yang tidak dikirim dipertahankan. Tema dan desain template disalin saat membuat undangan agar perubahan template selanjutnya tidak mengubah undangan tersebut.

Definisi tampilan template disimpan di database pada `templates.data.presentation`: versi, jenis pernikahan/perayaan, gaya dasar, palet, dan komponen bawaan. Frontend memakai satu renderer umum; tidak ada registry ID template yang perlu ditambah saat membuat template baru. `rendererId` tetap dikirim sebagai informasi asal template dan tidak menentukan apakah template dapat dirender. Stylesheet dan komponen React merupakan pustaka tampilan aplikasi. Template baru dapat menggabungkan komponen yang tersedia, warna, aset unggahan, posisi, dan animasi dari editor tanpa menambah file JSX.

Konfigurasi awal 44 template terdapat pada `database/seeds/presentations.json` untuk pemasangan/migrasi. Setup mengisi konfigurasi yang belum ada pada template bawaan, template custom, dan undangan lama tanpa menimpa pengaturan yang sudah tersimpan. Pembacaan saat aplikasi berjalan selalu dari database. Daftar frontend tidak menampilkan koleksi hardcode saat API gagal.

Desain drag/drop disimpan pada `data.design` versi 1: variasi komponen, ornamen tambahan per bagian, serta perubahan ornamen bawaan. Tema di `data.theme` mendukung warna hex, font, gradasi 2–5 warna, sudut, opasitas, dan pengaturan per bagian. Presentasi, desain, dan tema divalidasi; API tidak menerima CSS/JavaScript bebas. Undangan baru menyimpan salinan presentasi supaya perubahan komponen bawaan template tidak mengubah undangan yang sudah dibuat.

Daftar tamu disimpan dalam satu row dokumen JSON per undangan, maksimal 5.000 nama. PATCH mengirim `revision`, `guests`, dan `messageTemplate`; revisi yang sudah berubah menghasilkan 409 agar tab lain tidak tertimpa. Pesan custom harus menyertakan `{link_undangan}`. Ekspor/impor Excel dikerjakan frontend.

## Kiriman customer

| Metode | Endpoint | Fungsi |
| --- | --- | --- |
| POST | `/api/customer-submissions` | Membuka sesi kiriman |
| GET | `/api/customer-submissions/:id` | Status dan daftar gambar yang sudah diterima |
| POST | `/api/customer-submissions/:id/images/:hash` | Mengunggah gambar satu per satu |
| POST | `/api/customer-submissions/:id/complete` | Menyelesaikan kiriman menjadi pending |
| GET | `/api/admin/submissions` | Inbox; filter status |
| GET / DELETE | `/api/admin/submissions/:id` | Detail / hapus kiriman |
| GET | `/api/admin/submissions/:id/images/:hash` | Preview gambar privat |
| POST | `/api/admin/submissions/:id/accept` | Terima menjadi draft dengan `slug` |
| POST | `/api/admin/submissions/:id/reject` | Tolak dengan `note` opsional |

Endpoint customer setelah pembuatan sesi memerlukan bearer token rahasia kiriman tersebut. Kiriman berisi `id`, `token`, `contact`, `form`, `guestList`, dan `assets`; gambar lokal direferensikan sebagai `customer-image:<sha256>`. Upload dibatasi 80 gambar, masing-masing 5 MB, total 40 MB. Gambar dipindahkan ke media publik ketika diterima; penerimaan memakai transaksi dan row lock, dapat diulang tanpa membuat undangan ganda. Undangan tetap draft sampai admin menerbitkan. Menghapus kiriman tidak menghapus undangan yang sudah dibuat. Sesi upload belum selesai dibersihkan setelah 24 jam saat menerima kiriman baru.

## Format respons dan batas

Respons sukses: `{ "success": true, "data": ... }`; daftar berpaginasi menambahkan `meta: { total, limit, offset }`. Gagal: `{ "success": false, "message": "..." }`. Pagination memakai `limit` 1–100 dan `offset` mulai nol.

Endpoint koleksi template hanya mengambil metadata, presentasi dasar, serta warna latar/aksen untuk palet. Isi undangan, lapisan ornamen, dan warna per bagian diambil melalui endpoint detail saat diperlukan. Respons JSON memakai gzip jika PHP mendukung zlib dan klien menerima gzip. Gambar serta unduhan biner tetap dikirim langsung dengan Content-Length aslinya.

Body JSON standar 32 KB; template 512 KB, undangan 1 MB, daftar tamu 2 MB, kiriman customer 4 MB. Upload gambar menggunakan body biner dengan `Content-Type: image/jpeg`, `image/png`, atau `image/webp`, maksimal 5 MB. Frontend mengunggah saat gambar dipilih, lalu menyimpan URL saat simpan data.

Rate limit disimpan pada file dengan lock agar berlaku antar-request/proses PHP: operasi publik 20/menit/IP, sesi kiriman customer 5/menit/IP, gambar kiriman 120/menit/IP. IP berasal dari koneksi server; header proxy tidak dipercaya otomatis.

## Deployment

Gunakan Apache/Nginx dengan DocumentRoot ke `public`, arahkan endpoint `/api/*` ke `public/index.php`, dan batasi akses folder lainnya. `.htaccess` sudah melindungi folder proyek ketika dijalankan di subfolder XAMPP. Nginx membutuhkan aturan front controller sendiri karena tidak membaca `.htaccess`. Gunakan origin frontend yang sama melalui reverse proxy agar cookie dan URL gambar bekerja tanpa perubahan frontend. Aktifkan HTTPS dan `COOKIE_SECURE=true` di produksi.

Kesalahan internal dicatat ke error log PHP/Apache. Pada XAMPP periksa `C:\xampp\apache\logs\error.log`. Detail password, token, dan konfigurasi database tidak dikirim ke browser. File `.env`, `data`, serta `storage` tidak masuk Git.

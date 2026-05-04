# Treadmill Monitoring - Docker Deployment

Panduan ini menyiapkan aplikasi PHP + MySQL + OCR Python dalam container Docker, sehingga tidak perlu instal manual Apache/MySQL/Python di host.

## Stack yang digunakan

- PHP 8.2 + Apache
- MySQL 8.0
- phpMyAdmin
- Python 3 + EasyOCR + OpenCV

## Prasyarat

- Docker Desktop (Windows/Mac) atau Docker Engine (Linux)
- Docker Compose

Cek versi:

```bash
docker --version
docker compose version
```

## Struktur file Docker

- `Dockerfile`
- `docker-compose.yml`
- `requirements.txt`
- `.dockerignore`

## Jalankan aplikasi

Dari root project ini, jalankan:

```bash
docker compose up -d --build
```

Jika build pertama cukup lama, itu normal karena image Python OCR sedang diunduh dan diinstal.

## Akses aplikasi

- Aplikasi utama: http://localhost:8080
- Riwayat: http://localhost:8080/riwayat.php
- phpMyAdmin: http://localhost:8081

Credential default database:

- Host: `db` (dari dalam container app) / `localhost:3307` (dari host)
- Database: `treadmill_db`
- User: `treadmill_user`
- Password: `treadmill_pass`
- Root password: `root`

## Konfigurasi database di aplikasi

File `config/db_config.php` sudah dibuat agar mendukung environment variable:

- `DB_HOST`
- `DB_USER`
- `DB_PASS`
- `DB_NAME`

Nilai default tetap kompatibel untuk mode non-Docker.

## Cek status container

```bash
docker compose ps
```

Lihat log jika ada masalah:

```bash
docker compose logs -f app
docker compose logs -f db
```

## Perintah penting

Stop container:

```bash
docker compose down
```

Stop dan hapus volume database (reset data):

```bash
docker compose down -v
```

Build ulang setelah ubah Dockerfile/requirements:

```bash
docker compose up -d --build
```

## Catatan OCR

OCR dijalankan oleh endpoint upload dan akan memanggil script Python `scripts/read_time.py` di background.

File output OCR:

- `uploads/latest_result.json`
- `uploads/ocr_status.json`

Jika OCR tidak jalan:

1. Cek log app: `docker compose logs -f app`
2. Pastikan file gambar sudah masuk ke folder `uploads/`
3. Pastikan dua foto tersedia (`latest_time.jpg` dan `latest_distance.jpg`) sebelum OCR diproses

## Deployment VPS / Hosting

Kalau kamu pindah ke VPS, ada 2 pilihan:

1. Tetap pakai Docker seperti panduan di atas.
2. Jalankan manual dengan Nginx/Apache + PHP 8.2 + MySQL + Python 3.

Langkah yang perlu disesuaikan:

1. Upload source code ke folder web root VPS, lalu pastikan permission `uploads/` bisa ditulis.
2. Set environment database di server: `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`.
3. Kalau OCR dipakai, set juga `PYTHON_BIN` dan `OCR_SCRIPT` bila lokasi Python atau script berbeda dari default.
4. Ubah `BASE_URL` di [esp32cam_rfid_lcd_treadmill.ino](esp32cam_rfid_lcd_treadmill.ino#L54) ke alamat web yang benar. Kalau app ada di root server, pakai `http://76.13.23.138:4001`; kalau app ada di subfolder, pakai `http://76.13.23.138:4001/treadmill`.
5. Kalau pakai HTTPS di VPS, sketch ESP32 sudah disiapkan untuk `WiFiClientSecure`.
6. Kalau yang kamu punya masih alamat IP seperti `http://76.13.23.138:4001/index.php`, itu boleh untuk testing, tapi untuk ESP32 sebaiknya pakai base URL server-nya, misalnya `http://76.13.23.138:4001` atau `http://76.13.23.138:4001/treadmill`, bukan `index.php` langsung.
7. Untuk RFID, cukup kirim UID dari sketch. Nama user akan diambil dari tabel `members` di database; kalau UID baru, server akan bikin nama default otomatis.
8. WiFi di ESP harus yang dipakai ESP untuk konek ke internet. Tidak harus sama dengan WiFi di server/VPS. Yang penting ESP bisa menjangkau alamat VPS itu lewat jaringan yang dipakai.

Contoh environment untuk PHP-FPM / Apache:

```bash
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=treadmill_user
DB_PASS=secret
DB_NAME=treadmill_db
PYTHON_BIN=/usr/bin/python3
OCR_SCRIPT=/var/www/treadmill/scripts/read_time.py
```

## Keamanan minimum (production)

Sebelum production, lakukan ini:

1. Ganti password database dan jangan pakai kredensial default.
2. Pakai HTTPS di domain VPS.
3. Batasi akses endpoint device jika memungkinkan.
4. Pastikan folder `uploads/` tidak bisa dieksekusi langsung sebagai script.
5. Kalau OCR tidak dibutuhkan, nonaktifkan jalur eksekusi background Python di server.

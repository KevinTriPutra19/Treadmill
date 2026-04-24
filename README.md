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

## Deployment server

Untuk deploy ke server:

1. Install Docker + Compose di server.
2. Upload source code project.
3. Jalankan `docker compose up -d --build`.
4. Buka port `8080` (app) dan opsional `8081` (phpMyAdmin).
5. Jika memakai reverse proxy (Nginx/Traefik), arahkan domain ke service app port 8080.

## Keamanan minimum (production)

Sebelum production, ubah nilai berikut di `docker-compose.yml`:

- Password root MySQL
- User/password database aplikasi
- Matikan phpMyAdmin jika tidak diperlukan publik

Disarankan juga:

- Gunakan backup volume database berkala
- Letakkan aplikasi di belakang reverse proxy HTTPS
- Batasi akses endpoint device bila memungkinkan

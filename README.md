# Azatech Online Shop — Order Management

[![CI](https://github.com/iqfar12/azatech-test/actions/workflows/ci.yml/badge.svg)](https://github.com/iqfar12/azatech-test/actions/workflows/ci.yml)

Technical test **Senior Fullstack Developer** — PT Laksmada Aza Simetri.
Sistem online shop untuk mengelola produk, pelanggan, dan order multi-item dengan siklus status, dua role, pemrosesan asinkron, dan konsistensi stok saat banyak kasir membuat order bersamaan.

NOTE: Git user yang tercantum di commit sepertinya bukan git saya yang di repo, mungkin karena git init dilakukan di claude namun sudah saya ubah dengan Commit perbaikan README.md ini

## Tech stack & alasan

| Bagian | Pilihan | Alasan |
|---|---|---|
| Backend | **Track B — Laravel 13**, PHP 8.4, `declare(strict_types=1)` | Track yang dipilih; Eloquent + Form Request + Policy + Queue memberi semua blok bangunan yang dibutuhkan. |
| Database | **PostgreSQL 17** | `SELECT … FOR UPDATE`, CHECK constraint, partial/covering index, `INSERT … ON CONFLICT … RETURNING`, `generate_series` untuk laporan. |
| Queue & cache | **Redis 7** (Laravel Queue, cache, lock, rate limiter) | Wajib untuk level Senior; satu dependensi untuk semuanya. |
| Auth | **Laravel Sanctum** personal access token (expiry + revoke saat logout) | Sesuai Track B — lihat ADR-003. |
| API docs | **Scramble** (OpenAPI 3, otomatis dari kode) | Dokumentasi tidak bisa basi karena dibaca dari route, Form Request, dan Resource. |
| Testing | **Pest 4** terhadap PostgreSQL sungguhan + test concurrency dengan `pcntl_fork` | Perilaku locking SQLite berbeda; concurrency harus diuji dengan proses & koneksi sungguhan. |
| Kualitas | **Larastan level 6**, **Laravel Pint** | Static analysis & format di CI. |
| Server | **FrankenPHP** (Caddy + PHP dalam satu binary) | Satu proses, image kecil, berjalan non-root. |
| Frontend | **Vue 3 + Vite + TypeScript (strict)**, **TanStack Vue Query**, **Zod**, **Tailwind CSS 4**, vue-router | Ringan, type-safe; TanStack Query untuk cache server state + invalidasi setelah mutasi. |
| Test frontend | **Vitest + Testing Library** | Component test untuk form order. |
| Infra | Docker Compose, Dockerfile multi-stage non-root, GitHub Actions | Satu perintah menjalankan semuanya; CI lint → analisis → test → build → smoke test stack. |

## Quick start

Prasyarat: Docker + Docker Compose v2.

```bash
docker compose up --build
```

Itu saja: migration dan seeder berjalan otomatis saat container `api` pertama kali naik (APP_KEY dibuat otomatis dan dibagikan ke worker/scheduler lewat volume). Opsional: `cp .env.example .env` untuk mengganti password/port.

| Service | URL |
|---|---|
| Web (SPA) | http://localhost:8080 |
| API | http://localhost:8000/api |
| **OpenAPI (Scramble UI)** | **http://localhost:8000/docs/api** · JSON: http://localhost:8000/docs/api.json |
| Health check | http://localhost:8000/api/health |
| Mailpit (e-mail invoice & stok menipis) | http://localhost:8025 |

Container: `db` (PostgreSQL), `redis`, `mailpit`, `api`, `worker` (`queue:work`), `scheduler` (`schedule:work`), `web` (nginx).

### Akun login (seeder)

| Role | Email | Password |
|---|---|---|
| admin | `admin@azatech.id` | `password` |
| cashier | `cashier@azatech.id` | `password` |

Password demo bisa diganti lewat `SEED_ADMIN_PASSWORD` / `SEED_CASHIER_PASSWORD`. Data seed: 55 produk, 30 pelanggan, 150 order dengan semua status tersebar di 90 hari terakhir — dibuat lewat service aplikasi yang asli, jadi stok, ledger, histori status, dan nomor order konsisten.

Reset total: `docker compose down -v && docker compose up --build`.

## Menjalankan test

**Backend (di Docker, terhadap PostgreSQL & Redis compose):**

```bash
docker compose up -d db redis
docker compose --profile test run --rm api-test
```

**Backend (lokal, PHP 8.4 dengan ekstensi `pdo_pgsql`, `pcntl`, `posix`):**

```bash
docker compose up -d db redis          # database online_shop_test dibuat otomatis
cd backend
composer install
cp .env.example .env && php artisan key:generate
vendor/bin/pint --test                 # lint
vendor/bin/phpstan analyse             # Larastan level 6
php artisan test                       # Unit + Feature + Concurrency
php artisan test --testsuite=Concurrency
```

**Frontend:**

```bash
cd frontend
npm install
npm run lint && npm run type-check && npm test && npm run build
npm run dev                            # http://localhost:5173 (proxy /api → :8000)
```

### Test penting

| Kebutuhan | Test |
|---|---|
| 20 order paralel, stok 5 → tepat 5 sukses, 15 gagal, stok 0 | `tests/Concurrency/StockConcurrencyTest.php` |
| Multi-item urutan terbalik tanpa deadlock | idem |
| Cancel bersamaan → stok kembali sekali | idem |
| Idempotency-Key sama dikirim bersamaan → satu order | idem |
| Replay / payload beda (422) / masih diproses (409) / TTL | `tests/Feature/IdempotencyTest.php` |
| State machine (tabel transisi) & perhitungan total | `tests/Unit/*` |
| Transisi + 409 + role per aksi + ledger + audit | `tests/Feature/OrderLifecycleTest.php` |
| RBAC per role, CRUD, pagination/search/sort | `ProductTest`, `CustomerTest` |
| Job invoice tidak mengirim e-mail ganda, notifikasi stok menipis | `tests/Feature/StockAndJobsTest.php` |
| Laporan (zona waktu, periode kosong, satu query), cache dashboard | `tests/Feature/ReportAndDashboardTest.php` |
| Request id, 500 tanpa stack trace, CORS, health | `tests/Feature/ObservabilityTest.php` |
| Form order (total real-time, validasi, error per item, double submit, Idempotency-Key) | `frontend/src/components/__tests__/OrderForm.spec.ts` |

## Ringkasan API

Semua endpoint di bawah `/api`, response memakai envelope `{ success, message, data, meta? }` atau `{ success: false, message, errors? }`. Dokumentasi lengkap: **`/docs/api`**.

| Endpoint | Method | Role |
|---|---|---|
| `/auth/login` · `/auth/logout` · `/auth/me` | POST · POST · GET | semua |
| `/products` · `/products/{id}` | GET, POST · GET, PUT, DELETE | semua (baca) · admin (tulis) |
| `/products/{id}/stock-adjustments` · `/products/{id}/stock-movements` | POST · GET | admin |
| `/customers` · `/customers/{id}` | GET, POST · GET, PUT, DELETE | semua · DELETE admin |
| `/orders` (filter `status`, `date_from`, `date_to`, `customer_id`, `search`) | GET, POST | semua |
| `/orders/{id}` · `/orders/{id}/invoice` | GET | semua |
| `/orders/{id}/pay` | POST | admin, cashier |
| `/orders/{id}/ship` · `/complete` · `/cancel` | POST | admin |
| `/dashboard/summary` (cached) | GET | semua |
| `/notifications/low-stock` | GET | admin |
| `/reports/sales?from&to&group_by=day\|month` · `/reports/top-products?from&to&limit&sort_by` | GET | admin |
| `/health` | GET | publik |

Contoh membuat order (harga & total dihitung server; `Idempotency-Key` wajib UUID):

```bash
curl -X POST http://localhost:8000/api/orders \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"customer_id":"<uuid>","items":[{"product_id":1,"quantity":2},{"product_id":3,"quantity":1}]}'
```

Status code: 200, 201, 204, 401, 403, 404, 409 (transisi tidak valid / key masih diproses), 422 (validasi, stok tidak cukup, key dipakai ulang dengan payload beda), 429, 500.

## Struktur repository

```
.
├── backend/                 Laravel 13 API (lihat ARCHITECTURE.md §2)
│   ├── app/Domain           state machine, kalkulator, Money
│   ├── app/Services         order, stok+ledger, idempotency, laporan, dashboard
│   ├── app/Http             controller tipis, Form Request, Resource, middleware
│   ├── app/Policies         RBAC
│   ├── app/Jobs             invoice, notifikasi stok menipis
│   ├── database/            migrations, factories, seeders
│   ├── tests/               Unit · Feature · Concurrency (Pest)
│   └── Dockerfile           multi-stage (vendor → base → test | runtime), non-root
├── frontend/                Vue 3 + TS SPA
│   ├── src/api · auth · router · composables · components · pages
│   └── Dockerfile           build → nginx-unprivileged
├── docker/postgres/init     membuat database test
├── docker-compose.yml
├── .github/workflows/ci.yml
├── ARCHITECTURE.md          diagram, layer, ADR, skala 50k order/hari
├── DECISIONS.md             jawaban 4 pertanyaan wajib
└── AI_USAGE.md              penggunaan AI
```

## Pemetaan requirement

| Requirement | Status | Di mana |
|---|---|---|
| 4.1 Auth Sanctum (expiry, revoke) | ✅ | `AuthService`, `config/sanctum.php` |
| 4.1 CRUD produk & pelanggan (pagination, search, sort, soft delete, validasi) | ✅ | `ProductController`, `CustomerController`, `Requests/*` |
| 4.1 Order multi-item dalam satu transaksi, harga server, snapshot, nomor unik, reservasi stok | ✅ | `OrderService`, `OrderNumberGenerator` |
| 4.1 Seeder (1 admin, 1 cashier, ≥50 produk, 30 customer, 150 order / 90 hari) | ✅ | `DatabaseSeeder` |
| 4.2 State machine di domain, 409 untuk transisi tidak valid | ✅ | `Domain/Order/*` |
| 4.3 No overselling, anti-deadlock, cancel ganda aman, test concurrency | ✅ | `StockService`, `tests/Concurrency` |
| 4.4 Idempotency-Key (replay, payload beda, in-progress, TTL) | ✅ | `IdempotencyService`, ADR-002 |
| 4.5 Stock ledger, adjustment admin, riwayat | ✅ | `StockService`, `StockController` |
| 4.6 RBAC di backend | ✅ | `Policies/*`, `FormRequest::authorize()` |
| 4.7 Job invoice + e-mail (Mailpit), notifikasi stok menipis, retry/backoff, failed, aman diulang | ✅ | `Jobs/*`, ADR-006 |
| 4.8 Laporan sales & top products (agregasi DB), index, cache dashboard + invalidasi | ✅ | `ReportService`, `DashboardService` |
| 4.9 Rate limit, CORS eksplisit, mass assignment, JSON log + request_id, health, no stack trace | ✅ | `AppServiceProvider`, `AssignRequestId`, `ApiExceptionRenderer` |
| 4.10 Unit, feature, concurrency, idempotency test di PostgreSQL | ✅ (ditulis, lihat catatan) | `backend/tests` |
| 4.11 Compose (api, worker, web, db, redis, mailpit), Dockerfile non-root, CI, OpenAPI | ✅ | `docker-compose.yml`, `.github/workflows/ci.yml`, Scramble |
| 5 Frontend 1–9 | ✅ | `frontend/src` |
| 5.10 Component test form order | ✅ | `OrderForm.spec.ts` |
| Bonus: audit log | ✅ | `AuditLogger` |
| Bonus: E2E Playwright, shared types/codegen, export Excel, Prometheus/OTel | ❌ | lihat di bawah |

## Yang belum selesai / catatan jujur

- **Lock file belum di-commit.** Repo ditulis di lingkungan tanpa akses Packagist/npm, sehingga `composer.lock` dan `package-lock.json` belum ada. Dockerfile & CI sudah menangani (fallback `composer install` / `npm install`), tetapi untuk build yang reproducible: jalankan `composer install` dan `npm install` sekali lalu commit kedua lock file.
- **Pipeline belum pernah dijalankan hijau** di lingkungan penulisan (tidak ada Docker daemon). Yang sudah diverifikasi: sintaks seluruh PHP, sintaks TypeScript/template Vue, kelas domain murni, dan SQL inti (locking paralel, constraint, nomor order, query laporan, klaim idempotency) terhadap PostgreSQL 16 sungguhan. Detail di AI_USAGE.md dan DECISIONS.md (d).
- **E2E Playwright** — belum. Rencana: login kasir → buat order → admin ship/complete, dijalankan di job `docker` CI.
- **Shared types / OpenAPI codegen** — `frontend/src/api/types.ts` masih manual. Rencana: `openapi-typescript` dari `/docs/api.json`.
- **Export Excel/CSV sebagai job**, **Prometheus/OpenTelemetry**, **Horizon** — belum.
- **Order PENDING tidak kedaluwarsa** (stok tertahan sampai dibatalkan) — rencana auto-cancel terjadwal.
- Dropdown produk/pelanggan di form order memuat maksimal 100 baris (cukup untuk data seed; katalog besar perlu combobox pencarian server).

## AI Usage

Lihat **[AI_USAGE.md](./AI_USAGE.md)** 

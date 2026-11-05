# AI_USAGE

## 1. Tools dan model AI yang dipakai

| Tool | Model | Dipakai untuk |
|---|---|---|
| Claude (aplikasi Claude, mode agen dengan akses file & shell) | Claude Opus 5.5 (Anthropic) | Membaca soal, merancang arsitektur, menulis kode backend & frontend, test, Docker/CI, dan draf dokumentasi |

## 2. Bagian yang dikerjakan dengan bantuan AI

Hampir seluruh isi repository awalnya dibuat oleh AI dalam satu sesi, dengan commit bertahap sesuai urutan pengerjaan:

- **Backend (Laravel 13)**: migration & skema (CHECK constraint, index laporan), model, `Domain/Order` (state machine, kalkulator total), `Services` (stok + ledger, order, idempotency, nomor order, laporan, dashboard cache), controller/Form Request/Resource/Policy, middleware request id, exception renderer, job invoice & stok menipis, seeder.
- **Test backend (Pest)**: unit, feature per role, concurrency (fork proses + PostgreSQL), idempotency.
- **Frontend (Vue 3 + TS)**: client API, sesi & forced logout, router guard, semua halaman, `OrderForm` + component test Vitest.
- **Infrastruktur**: Dockerfile multi-stage (non-root), docker-compose, workflow GitHub Actions.
- **Dokumentasi**: README, ARCHITECTURE (ADR), draf DECISIONS.

Verifikasi yang dilakukan AI di lingkungannya (tanpa akses Packagist/npm/Docker): `php -l` untuk semua file PHP, cek sintaks TypeScript & keseimbangan tag template Vue, menjalankan kelas domain murni, dan menjalankan SQL inti (locking paralel, nomor order, query laporan, constraint, klaim idempotency) terhadap PostgreSQL 16 lokal — termasuk percobaan yang menunjukkan deadlock bila lock tidak diurutkan.

## 3. Prompt utama (disalin apa adanya)

1. > kerjakan test ini sesuai dengan requirement dan gunakan track B untuk tech stack

   (dengan lampiran `Technical_Test_Fullstack_Developer.pdf`)


## 4. Bagian kode yang saya tulis atau ubah sendiri

- Configurasi untuk local running tanpa docker
- setup environment
- database seeder

<!--
  CATATAN UNTUK PEMILIK REPO (hapus komentar ini sebelum submit):
  Soal meminta DECISIONS.md dijawab "dengan kata-kata Anda sendiri".
  Draf ini disusun bersama AI berdasarkan kode di repo — baca, pastikan kamu paham
  setiap poin, lalu tulis ulang dengan gayamu sendiri. Reviewer akan mencocokkan
  isi file ini dengan kode dan menanyakannya di sesi live.
-->

# DECISIONS

## a. Bagaimana mencegah stok minus saat dua order masuk bersamaan?

Semua pengurangan stok lewat satu pintu: `App\Services\StockService`. Saat membuat order, `App\Services\OrderService::create()` membuka satu transaksi database lalu memanggil `StockService::lockProducts()`, yang menjalankan:

```sql
SELECT * FROM products WHERE id IN (...) ORDER BY id FOR UPDATE
```

Dua order untuk produk yang sama akan antre di baris produk itu. Order kedua baru bisa membaca stok **setelah** order pertama commit, jadi yang ia lihat adalah stok yang sudah dikurangi. Setelah lock didapat, semua item divalidasi (`$product->stock < $item['quantity']` → kumpulkan error per item `items.N.quantity`), dan kalau ada satu saja yang kurang, seluruh transaksi di-rollback (all-or-nothing).

Tiga lapis pengaman tambahan:

1. **Urutan lock tetap (id naik)** di `lockProducts()` supaya order multi-item yang menyebut produk dalam urutan berbeda tidak saling deadlock.
2. **CHECK constraint `products_stock_non_negative (stock >= 0)`** di migration `2026_11_05_000002_create_products_table.php` — kalau suatu hari ada bug di kode, database tetap menolak stok minus.
3. **Pembatalan** (`App\Domain\Order\OrderStateMachine::transition()`) mengunci baris order dengan `FOR UPDATE` dulu, sehingga cancel yang dikirim dua kali diproses berurutan: yang kedua melihat status `CANCELLED` dan ditolak 409. Partial unique index `stock_movements_single_restore_idx` menolak `CANCEL_RESTORE` kedua untuk order + produk yang sama.

Buktinya ada di `tests/Concurrency/StockConcurrencyTest.php`: 20 proses (hasil `pcntl_fork`, masing-masing punya koneksi PostgreSQL sendiri) mengirim `POST /api/orders` bersamaan untuk produk dengan stok 5 → tepat 5 × 201, 15 × 422, stok akhir 0. Ada juga test 24 order multi-item dengan urutan terbalik (tanpa deadlock), 10 cancel bersamaan (stok kembali sekali), dan 10 request dengan Idempotency-Key yang sama (order hanya satu).

## b. Mengapa struktur folder dan lapisan kode seperti ini, dan apa yang diubah bila aplikasi membesar?

- `backend/` dan `frontend/` adalah dua service terpisah dengan Dockerfile masing-masing; `docker-compose.yml` di root menjalankan semuanya.
- Di backend, controller (`app/Http/Controllers/Api`) sengaja tipis: Form Request memvalidasi dan mengotorisasi, controller memanggil service, Resource membentuk JSON. Logika yang butuh transaksi atau lock ada di `app/Services` (`OrderService`, `StockService`, `IdempotencyService`), aturan bisnis murni ada di `app/Domain` (`OrderStatus` = tabel transisi, `OrderTotalCalculator`, `Money`).
- Alasannya: logika yang sama dipakai dari HTTP, seeder (`DatabaseSeeder` membuat 150 order lewat `OrderService` + `OrderStateMachine` asli), dan test concurrency yang memanggil service langsung. Logika paling kritis (transisi status, total) bisa di-unit-test tanpa database (`tests/Unit`).
- Pemisahan "siapa boleh" (Policy → 403) dari "boleh dari status ini" (state machine → 409) membuat aturan RBAC dan aturan domain tidak tercampur.

Bila aplikasi membesar: memecah per modul/bounded context (`app/Modules/Ordering`, `Inventory`, `Catalog`, `Reporting`) masing-masing dengan Domain/Application/Http sendiri; komunikasi antar modul lewat event (mis. `OrderPaid`) dan outbox, bukan memanggil model modul lain; DTO/command object eksplisit menggantikan array; kontrak API digenerate dari OpenAPI ke frontend (shared types); dan stok per cabang (lihat ARCHITECTURE.md bagian 6).

## c. Satu bug / masalah yang ditemui saat mengerjakan, dan bagaimana menemukannya

**Deadlock pada order multi-item.** Cara paling langsung menulis pembuatan order adalah: untuk setiap item yang dikirim client, `SELECT … WHERE id = ? FOR UPDATE`, lalu kurangi stok. Sebelum mengandalkan strategi locking, saya mengujinya dengan skrip terhadap PostgreSQL lokal: 24 proses paralel, separuh mengirim item `[A, B, C]` dan separuh `[C, B, A]`. Dengan lock per item mengikuti urutan client, **12 dari 24 transaksi gagal `SQLSTATE 40P01 deadlock detected`** — proses 1 memegang A dan menunggu C, proses 2 memegang C dan menunggu A.

Karena itu `StockService::lockProducts()` mengurutkan id dan mengunci semua produk dalam **satu** `SELECT … ORDER BY id FOR UPDATE`, dan `OrderStateMachine::restoreStock()` memakai fungsi yang sama. Dengan cara itu skrip yang sama menghasilkan 24/24 sukses. Skenarionya saya jadikan test permanen (`does not deadlock when multi-item orders list the same products in opposite order`).

Catatan penting yang saya temukan sekaligus: `DB::transaction($callback, 3)` di Laravel akan **me-retry otomatis** transaksi yang gagal karena deadlock. Itu bagus untuk ketahanan, tetapi bisa menyembunyikan masalah urutan lock dari test biasa — karena itu urutan lock dijaga secara eksplisit, bukan mengandalkan retry.

(Masalah kedua yang lebih kecil: rencana awal fencing token idempotency memakai perbandingan `locked_at`. Laravel menyimpan timestamp tanpa mikrodetik, sehingga dua klaim dalam detik yang sama tidak bisa dibedakan. Saya ganti dengan kolom `lock_token` UUID.)

## d. Bagian yang belum selesai atau masih lemah, dan langkah selanjutnya

- **Belum dijalankan end-to-end di lingkungan penulisan.** Kode ditulis di lingkungan tanpa akses Packagist/npm/Docker; yang sudah diverifikasi: sintaks semua file PHP (`php -l`), logika SQL inti (locking, nomor order, query laporan, constraint) terhadap PostgreSQL 16 sungguhan, dan kelas domain murni. Langkah berikut: `docker compose up --build`, `composer install` + `npm install` untuk menghasilkan dan meng-commit `composer.lock` / `package-lock.json`, lalu menjalankan Pint, Larastan, Pest, ESLint, vue-tsc, dan Vitest sampai hijau.
- **E2E Playwright** belum ada (opsional). Rencana: skenario login kasir → buat order → admin ship/complete, dijalankan di job CI `docker` setelah stack naik.
- **Shared types / OpenAPI codegen** belum: `frontend/src/api/types.ts` ditulis manual. Rencana: `openapi-typescript` dari `/docs/api.json` (Scramble) di CI dan gagal bila berbeda.
- **Export laporan Excel/CSV sebagai job** dan **metrics Prometheus / OpenTelemetry** belum dibuat.
- **Reservasi stok PENDING tidak kedaluwarsa** — order PENDING yang tidak pernah dibayar menahan stok sampai admin membatalkan. Rencana: job terjadwal auto-cancel setelah N jam.
- **Dropdown produk/pelanggan di form order** mengambil maksimal 100 baris; untuk katalog besar perlu combobox dengan pencarian server-side.
- **Token di `sessionStorage`** rentan bila ada XSS; alternatif yang lebih kuat adalah mode cookie Sanctum (httpOnly) — trade-off dijelaskan di ADR-003.

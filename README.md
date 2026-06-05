# Tumbas POS Backend

Backend Laravel untuk fitur point of sale minimal: kelola produk, checkout transaksi,
pengurangan stok otomatis, riwayat transaksi, dan ringkasan penjualan.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

API default Laravel berjalan di `http://127.0.0.1:8000/api`.

## Swagger

Jalankan server lalu buka dokumentasi interaktif:

```bash
php artisan serve
```

- Swagger UI: `http://127.0.0.1:8000/docs`
- OpenAPI JSON: `http://127.0.0.1:8000/docs/openapi.json`

## Endpoint

### Produk

- `GET /api/products` - daftar produk.
- `GET /api/products?q=kopi` - cari produk berdasarkan nama atau merek.
- `GET /api/products?low_stock=1` - daftar produk dengan stok 5 atau kurang.
- `POST /api/products` - tambah produk.
- `GET /api/products/{id}` - detail produk.
- `PUT /api/products/{id}` - update produk.
- `DELETE /api/products/{id}` - hapus produk.

Contoh tambah produk:

```json
{
  "nama": "Teh Botol",
  "merek": "Sosro",
  "stok": 12,
  "harga": 5000
}
```

### Transaksi

- `POST /api/sales` - checkout transaksi.
- `GET /api/sales` - daftar transaksi.
- `GET /api/sales/{id}` - detail transaksi beserta item.

Contoh checkout:

```json
{
  "cashier_name": "Kasir 1",
  "payment_method": "cash",
  "discount": 1000,
  "tax": 500,
  "paid": 20000,
  "items": [
    { "product_id": 1, "quantity": 2 }
  ]
}
```

Saat checkout berhasil, backend menghitung subtotal, total, kembalian,
menyimpan item transaksi, dan mengurangi stok produk.

### Laporan

- `GET /api/reports/summary` - ringkasan semua penjualan.
- `GET /api/reports/summary?from=2026-06-01&to=2026-06-04` - ringkasan per tanggal.

## Test

```bash
php artisan test
```

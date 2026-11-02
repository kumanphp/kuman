# Kuman Roadmap v1: Validasi, Injeksi, dan OpenAPI

Dokumen ini mencatat spesifikasi dan status fitur bergaya FastAPI di **Kuman**: input
bertipe yang tervalidasi otomatis, injeksi parameter handler, dan dokumentasi API yang
dibaca dari kode. Versi pertama dokumen ini memakai API yang tidak ada di Kuman
(`$router`, `response()`, `Webman\Http\Request`) dan container global. Versi ini sudah
direvisi mengikuti hasil analisis dan sesuai dengan kode yang dibangun.

Panduan pemakaian: [validation.md](docs/id/validation.md), [injection.md](docs/id/injection.md),
[openapi.md](docs/id/openapi.md). Desain internal: [architecture.md](docs/architecture.md).

---

## Urutan pengerjaan

```
[Tahap 1: Resolver + DTO] ──> [Tahap 2: provide / scoped / Depends] ──> [Tahap 3: OpenAPI + /docs]
```

Phase 1 dan 2 versi lama saling bergantung, karena injeksi DTO butuh resolver. Karena itu
keduanya digabung menjadi satu tahap.

---

## Tahap 1: Resolver dan DTO tervalidasi

```php
use Kuman\Validation\Attributes\{Email, MinLength};
use Kuman\Validation\Dto;

class CreateUser implements Dto
{
    #[Email]
    public string $email;

    #[MinLength(8)]
    public string $password;

    public int $age = 18;
}

$app->post('/users', fn(CreateUser $user) => ['email' => $user->email]);
```

Keputusan desain:

| Topik | Keputusan | Alasan |
|---|---|---|
| Penanda DTO | Interface `Kuman\Validation\Dto` | Bukan tebakan nama `*DTO` |
| Kapan reflection berjalan | Sekali di `App::start`, hasilnya plan per route | Spike: 52,4 µs/req vs 60,7 µs/req dengan reflection per request |
| Field wajib | Ditentukan tipe dan default; `#[Required]` juga menolak `''`/`[]` dan menentukan pesan | Mengikuti PHP, bukan duplikasi |
| `readonly ?string` tanpa default | Opsional, diisi `null` | PHP melarang default pada properti readonly |
| Koersi | JSON ketat; form, query, header, path dikonversi | JSON punya tipe, sumber lain hanya teks |
| Tipe salah | 422 dengan rule `type`, tidak pernah `TypeError` | `TypeError` = 500 akibat klien |
| Sumber data | GET/HEAD dari query, lainnya dari body; `#[Query]`, `#[Body]`, `#[Header]`, `#[Cookie]` untuk mengubah | `$request->all()` membuat `?role=admin` bisa mengisi field |
| JSON rusak | 400; JSON berupa list/skalar: 422 | Workerman mengubah JSON rusak menjadi `[]` |
| Format error | `{"error": "Unprocessable Entity", "details": [{field, rule, message}]}` | `rule` stabil untuk klien, pesan default berbahasa Inggris |
| Mengganti format/bahasa | `$app->onInvalid(fn(Invalid $e, Request $req) => ...)` | |
| Cara 422 dikirim | `Abort`/`Invalid` ditangkap di dalam pipeline route | Exception yang lolos ke `render()` melewati middleware (CORS hilang) dan tercatat sebagai error |
| Handler lama | Tidak berubah; injeksi hanya aktif kalau ada parameter yang butuh | `fn($req, $res, array $args)` tetap gaya Slim |
| Parameter tanpa tipe | Tidak pernah dibaca berdasarkan nama | `fn($req, $res, $id)` tetap menerima posisi |
| Kesalahan deklarasi | Menghentikan worker saat start | Sama seperti route yang salah |

Tipe yang didukung: `string`, `int`, `float`, `bool`, `array` (dengan `#[ArrayOf]`),
backed enum, DTO bersarang (termasuk yang memuat dirinya sendiri), `UploadedFileInterface`,
`mixed`. Rule: `Required`, `Email`, `MinLength`, `MaxLength`, `Min`, `Max`, `Numeric`,
`Pattern`, ditambah rule buatan sendiri lewat `Kuman\Validation\Rule`.

---

## Tahap 2: Injeksi dan masa hidup

Premis versi lama keliru: Kuman tidak punya container global, dan `Context` per fiber
sudah ada. Yang dibutuhkan adalah resolver tanpa state per request, bukan container yang
di-reset di `onMessage`, karena pada fiber loop reset itu menghapus state request lain yang
sedang suspend.

```php
return function (App $app) {
    $app->provide(PDO::class, new PDO($dsn));                 // per worker, dibuat setelah fork
    $app->scoped(Tenant::class, fn(#[Header('x-tenant')] string $slug, PDO $db) => findTenant($db, $slug));

    $app->get('/orders', fn(Tenant $tenant, PDO $db) => ordersOf($db, $tenant));
    $app->get('/hi', fn(#[Depends(CurrentLocale::class)] string $locale) => greet($locale));
};
```

| Didaftarkan dengan | Dibuat | Hidup selama |
|---|---|---|
| `provide()` | sekali, oleh aplikasi | umur worker |
| `scoped()`, `#[Depends]` | saat pertama diminta, maksimal sekali per request | request itu |
| DTO, parameter | setiap request | request itu |

Nilai per request disimpan di variabel lokal `Resolver::handle`, jadi tidak ada yang perlu
di-reset. Koneksi DB per request tidak didukung karena itu regresi dari pola Kuman.
Autentikasi tetap lewat guard, bukan contoh `AuthUser` di versi lama. Konstruktor
controller kini ikut di-inject.

`#[Depends]` menerima kelas invokable atau `[Kelas::class, 'method']`. Closure di dalam
attribute tidak bisa dipakai di PHP 8.2–8.4.

---

## Tahap 3: OpenAPI 3.1 dan `/docs`

```php
$app->enableDocs(path: '/docs', title: 'Kuman API', version: '1.0.0', ui: 'scalar', middleware: [$guard]);
```

| Topik | Keputusan |
|---|---|
| Versi | OpenAPI 3.1 (selaras JSON Schema), lolos `openapi-spec-validator` |
| Default | Nonaktif; dokumen mendaftar semua route, jadi pasang guard di luar development |
| UI | `scalar`, `swagger`, `redoc`, atau `null`; dari jsDelivr dengan versi terkunci dan SRI, tanpa bundel aset |
| Biaya | Dibangun pada request pertama ke dokumen, lalu di-cache per worker |
| Urutan route | Docs didaftarkan paling awal, jadi tidak gagal di belakang `/:slug` atau `/*` |
| Path | Lewat parser FastRoute: regex menjadi `pattern`, segmen opsional menjadi dua path, wildcard dijelaskan |
| `all()` | GET, POST, PUT, PATCH, DELETE |
| `ws()`, `sse()` | Tidak dicantumkan |
| Keamanan | Guard di route, group, `use('/prefix')`, dan global; `Auth::any` = alternatif, dua guard = keduanya wajib, `optional()` = boleh tanpa |
| Respons | `#[Returns(status, type, description)]` (bukan `Response`, agar tidak bentrok dengan `Kuman\Response`); return type kelas dipakai kalau tidak ada attribute |
| Metadata | `#[Summary]`, `#[Description]`, `#[Tag]`, `#[Hidden]` di closure, method, atau kelas controller |

---

## Checklist

### Tahap 1
- [x] `Kuman\Validation\Dto`, `Field`, `Hydrator`, `Rule`, `Invalid`, dan `Kuman\Abort`
- [x] Rule: `Required`, `Email`, `MinLength`, `MaxLength`, `Numeric`, `Min`, `Max`, `Pattern`, `ArrayOf`
- [x] Plan per route dibangun di `App::start`; pipeline route ikut dipindah ke sana
- [x] Koersi ketat untuk JSON, konversi untuk sumber teks; JSON rusak 400, list 422
- [x] 422 dikembalikan lewat middleware; `onInvalid()` untuk format sendiri
- [x] Bentuk handler lama tidak berubah (614 checks lama tetap lolos)

### Tahap 2
- [x] `Resolver` dengan memo lokal per request, tanpa container global
- [x] `provide()`, `scoped()`, `#[Depends]`, sumber `#[Query]`, `#[Header]`, `#[Cookie]`, `#[Body]`
- [x] Injeksi konstruktor controller
- [x] Error siklus dependency, parameter yang tidak bisa dipenuhi, dan union type muncul saat start
- [x] Isolasi di fiber loop diuji dengan tiga request yang suspend bersamaan

### Tahap 3
- [x] `OpenApi`: path, parameter, request body, skema komponen, security, respons 401/422
- [x] `enableDocs()` dengan Scalar, Swagger UI, Redoc, atau dokumen saja
- [x] `Prefixed` menggantikan closure `App::under` agar guard prefix terdeteksi
- [x] Dokumen fixture divalidasi dengan `openapi-spec-validator`; ketiga UI dirender di Chrome headless

### Ditunda
- [ ] `DateTimeImmutable` dan tipe tanggal lain di DTO
- [ ] Hook validasi lintas field di DTO (sementara: lempar `Invalid` dari handler)
- [ ] Injeksi untuk middleware berbentuk method controller
- [ ] Mode bundel aset UI untuk jaringan tanpa internet (sementara: `ui: null` dan viewer di `public/`)

---

## Prinsip

1. **Overhead rendah.** Reflection hanya saat start; handler tanpa injeksi dipanggil persis
   seperti sebelumnya.
2. **Kesalahan muncul saat start.** Tipe yang tidak didukung, parameter yang tidak bisa
   dipenuhi, dan siklus dependency menghentikan worker sebelum melayani request.
3. **Aman untuk proses long-running.** Tidak ada state per request di objek milik worker.
4. **Opt-in.** Validasi dan dokumentasi tidak berbiaya kalau tidak dipakai.

# Dokumentasi API

`enableDocs()` menyajikan dokumen OpenAPI 3.1 dari route Anda dan halaman untuk menjelajah
serta mencobanya. Dokumen dibaca dari route itu sendiri: path, method, parameter, skema
Dto, guard, dan error validasi. Tidak ada yang perlu ditulis manual, dan attribute
menambahkan apa yang tidak bisa diungkapkan oleh kode.

- [Mengaktifkan](#mengaktifkan)
- [Apa yang didokumentasikan](#apa-yang-didokumentasikan)
- [Menambah deskripsi](#menambah-deskripsi)
- [Respons](#respons)
- [Autentikasi](#autentikasi)
- [Yang tidak dicantumkan](#yang-tidak-dicantumkan)
- [Hal-hal yang sering menjebak](#hal-hal-yang-sering-menjebak)

## Mengaktifkan

```php
$app->enableDocs(
    path: '/docs',
    title: 'Orders API',
    version: '1.0.0',
    ui: 'scalar',            // 'scalar', 'swagger', 'redoc', atau null untuk dokumennya saja
    spec: '/openapi.json',
    middleware: [$guard],    // lihat di bawah
);
```

Lalu buka <http://localhost:8642/docs>. Dokumen dibangun pada request pertama ke dokumen itu
di setiap worker, jadi tidak ada biaya sampai seseorang membacanya.

Docs nonaktif sampai Anda memanggil `enableDocs()`, karena dokumen mendaftar semua route,
termasuk yang tidak Anda tautkan. Di luar development, pasang guard di `middleware` atau
panggil hanya kalau `getenv('APP_ENV') === 'local'`.

Halaman docs memuat script dari jsDelivr, dikunci ke satu versi dan diperiksa dengan
Subresource Integrity, sehingga browser menolak file yang berubah. Untuk jaringan tanpa
akses internet, pakai `ui: null` dan sajikan viewer sendiri dari `public/`.

Di dalam group, halaman dan dokumen mendapat prefix dan middleware group itu. Memanggil
`enableDocs()` setelah route catch-all seperti `/:slug` tetap jalan: docs didaftarkan
paling awal.

## Apa yang didokumentasikan

| Dari | Menjadi |
|---|---|
| `/users/:id(\d+)` | `/users/{id}`, parameter path dengan pola itu |
| `/a[/{b}]` | dua path, `/a` dan `/a/{b}` |
| `/files/*` | `/files/{wildcard}`, dengan penjelasan, karena parameter OpenAPI tidak bisa memuat garis miring |
| `all()` | GET, POST, PUT, PATCH, dan DELETE |
| `int $id` | parameter path integer |
| Dto di POST, PUT, PATCH, DELETE | body request JSON, multipart kalau ada upload |
| Dto di GET | satu parameter query per field |
| `#[Query]`, `#[Header]`, `#[Cookie]`, `#[Body]` | parameter itu |
| Rule | `format`, `minLength`, `minimum`, `pattern`, dan lainnya |
| Default, enum, tipe nullable | `default`, `enum`, `"type": ["string", "null"]` |
| Parameter fungsi `scoped()` dan `#[Depends]` | parameter setiap route yang memakainya |
| Handler yang memvalidasi | respons 422 dengan skema error |
| Guard | `security`, dan respons 401 |

## Menambah deskripsi

```php
use Kuman\Attributes\{Description, Summary, Tag};

#[Tag('orders')]
class OrderController
{
    #[Summary('Tampilkan satu order')]
    #[Description('Termasuk item dan alamat pengiriman.')]
    public function show(int $id): OrderView { ... }
}

$app->get('/health', #[Summary('Health check')] fn() => ['ok' => true]);
```

`#[Description]` juga bisa dipakai pada properti Dto dan parameter handler. `#[Tag]` pada
kelas controller berlaku untuk semua method-nya.

## Respons

Tanpa attribute, sebuah operasi punya respons 200. Return type yang menyebut kelas
menggambarkan body-nya:

```php
public function show(int $id): OrderView    // 200 dengan skema OrderView
```

`#[Returns]` menggambarkan respons secara eksplisit. Namanya bukan `Response`, supaya tidak
bentrok dengan `Kuman\Response` di file yang sama:

```php
use Kuman\Attributes\Returns;

#[Returns(201, OrderView::class, 'Created')]
#[Returns(200, [OrderView::class])]          // list
#[Returns(404, description: 'No such order')]
#[Returns(200, 'string')]                     // HTML
```

Kelas respons tidak harus Dto: properti public-nya yang digambarkan. Kelas dengan tipe
yang tidak bisa digambarkan dokumen, seperti `DateTimeImmutable`, tampil sebagai objek
biasa.

## Autentikasi

Guard ditemukan di mana pun dipasang: di route, di group, atau lewat
`use('/prefix', $guard)`.

| Guard | Security scheme |
|---|---|
| `Auth::basic()` | `basicAuth`, HTTP basic |
| `Auth::bearer()`, `Auth::jwt()` | `bearerAuth`, HTTP bearer |
| `Auth::apiKey()` | `apiKey.X-API-Key`, API key di header itu |
| `Auth::session()` | `sessionCookie`, cookie session |
| `Auth::any(...)` | salah satu guard-nya |
| `->optional()` | guard itu, atau tanpa guard |

Dua guard di satu route sama-sama wajib. Kelas guard buatan sendiri didokumentasikan kalau
meng-override `security()`:

```php
public function security(): array
{
    return ['partnerToken' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Partner']];
}
```

## Yang tidak dicantumkan

- Route WebSocket dan SSE, yang tidak bisa digambarkan oleh OpenAPI.
- Halaman docs dan dokumennya sendiri.
- Route yang ditandai `#[Hidden]`, pada handler atau kelas controller. Hanya disembunyikan
  dari dokumen: route-nya tetap menjawab.
- Apa yang dibaca handler biasa lewat `$req->get()` atau `$req->post()`. Minta Dto atau
  parameter bertipe agar ikut terdokumentasi.

## Hal-hal yang sering menjebak

- **Dokumen mengikuti apa yang diperiksa kode.** `onInvalid()` yang mengubah format error
  membuat skema 422 salah; gambarkan format Anda dengan `#[Returns(422, ...)]`.
- **Middleware global yang bukan guard tidak terlihat.** Closure yang menolak request tidak
  muncul di `security`.
- **Dokumen di-cache per worker.** Reload setelah perubahan akan membangunnya ulang.

<p align="center">
  <img src="https://image.web.id/images/logo-kuman.png" alt="Logo Kuman" width="160">
</p>

# Kuman

Microframework PHP untuk proses long-running, dibangun langsung di atas
[Workerman](https://github.com/walkor/workerman) dengan routing ala Express.
Satu file route, tanpa folder `config/`, dan HTTP, WebSocket, serta SSE berjalan
di satu port.

Butuh PHP 8.2+ dengan ekstensi `pcntl` dan `posix` (Linux atau macOS).

## Mulai

Mulai proyek baru dari [skeleton](https://github.com/kumanphp/app):

```bash
composer create-project kumanphp/app myapp
cd myapp
vendor/bin/kuman
```

Lalu buka <http://localhost:8642>. Definisikan route Anda di `app.php`.

### Tambahkan ke proyek yang sudah ada

```bash
composer require kumanphp/kuman
```

Buat `app.php`:

```php
<?php

use Kuman\App;
use Kuman\Request;

return function (App $app) {
    $app->get('/', fn() => 'Halo dari Kuman');
    $app->get('/users/:id', fn(Request $req) => ['id' => $req->param('id')]);
};
```

Jalankan, lalu buka <http://localhost:8642>:

```bash
vendor/bin/kuman
```

Saat berjalan di foreground, Kuman me-reload worker otomatis setiap ada file PHP
yang berubah.

## Routing

```php
$app->get('/users/:id', $handler);          // juga post, put, patch, delete, options
$app->all('/any', $handler);                // semua method
$app->route(['GET', 'POST'], '/form', $handler);

$app->get('/users/:id(\d+)', $handler);     // parameter dengan regex
$app->get('/files/*', $handler);            // $req->param('wildcard')
```

Daftarkan route statis sebelum route dinamis yang menutupinya, misalnya `/users/me`
sebelum `/users/:id`. Urutan terbalik membuat worker gagal start dan terus restart,
dengan pesan error di terminal atau di `runtime/kuman.log`.

Kelompokkan route dengan prefix. Argumen terakhir mendefinisikan route, argumen
sebelumnya adalah middleware untuk seluruh grup. Grup boleh bersarang, dan `use()` di
dalam grup berlaku untuk route yang didefinisikan sesudahnya di grup itu.

```php
$app->group('/api', $auth, function (App $api) {
    $api->get('/orders', $handler);                  // GET /api/orders
    $api->group('/v1', function (App $v1) {
        $v1->get('/orders', $handler);               // GET /api/v1/orders
    });
});
```

### Controller

Handler boleh berupa method controller, atau kelas invokable. Argumen sebelum handler
terakhir pada sebuah route adalah middleware route, dan itu juga boleh method
controller.

```php
$app->get('/users/:id', [UserController::class, 'show']);
$app->post('/users', [UserController::class, 'create']);
$app->get('/health', HealthController::class);       // punya __invoke()

$app->group('/admin', $auth, function (App $admin) {
    $admin->get('/users/:id', [UserController::class, 'show']);
});

class UserController
{
    public function show(Request $req)
    {
        return ['id' => $req->param('id')];          // mengembalikan seperti handler biasa
    }
}
```

Kuman membuat controller **untuk setiap request**, jadi properti tidak membawa data dari
satu request ke request berikutnya. Constructor controller tidak boleh punya argumen
wajib; untuk kebutuhan lain berikan instance, `[$controller, 'show']`, dan instance itu
menyimpan state-nya. Kelas atau method yang tidak ada, atau method private,
menghentikan worker saat start seperti kesalahan routing, bukan gagal belakangan di
tengah request. `ws()` tetap menerima callback.

## Handler dan response

> Semua method `Request` dan `Response`, dengan contoh dan tutorial langkah demi langkah:
> [docs/request-response.id.md](docs/request-response.id.md).

Handler menerima `Kuman\Request` dan **mengembalikan** response:

```php
fn() => 'teks';                        // HTML
fn() => ['ok' => true];                // JSON
fn() => Response::json($data, 201);
fn() => Response::text('teks', 400);
fn() => Response::redirect('/login');
fn(Request $req) => Response::file('/path/laporan.pdf', $req);
```

Yang tersedia di request: `param()`, `get()`, `post()`, `input()`, `all()`,
`header()`, `cookie()`, `file()`, `ip()`, dan `connection`. Body JSON otomatis
terbaca lewat `post()`. Kode yang tidak menerima `$req` bisa memakai
`Request::current()`.

Handler yang mendeklarasikan parameter kedua juga menerima `Response` kosong untuk
diisi dan parameter route, seperti di Slim:

```php
$app->get('/hello/{name}', function ($request, $response, array $args) {
    $response->getBody()->write("Halo, {$args['name']}");
    return $response->withStatus(201);
});
```

`{name}` dan `:name` sama saja, dan `$args` adalah `$req->params`. Tulis ke body
sebelum membuat salinan dengan `withStatus()` atau `withHeader()`, atau kembalikan
salinan yang Anda tulisi. Handler dengan satu parameter, atau tanpa parameter,
dipanggil seperti sebelumnya. Hanya handler terakhir sebuah route yang mendapat ini;
middleware route tetap `($request, $next)`. Seperti biasa, handler harus
**mengembalikan** response: tanpa `return`, Kuman menunggu `respond()` yang datang
belakangan.

`null` berarti handler memegang koneksi dan akan menjawab nanti. Handler yang lupa
`return` membuat klien menunggu terus.

```php
$app->get('/lambat', function (Request $req) use ($app) {
    $app->after(2, fn() => $req->respond(['selesai' => true]));
    return null;
});
```

## Middleware

Middleware menerima request dan `$next`. `$next` selalu mengembalikan response,
atau `null` untuk koneksi yang dipegang handler (WebSocket, SSE, respons tertunda).
Karena itu pakai `?->` saat mengubah hasilnya.

```php
$logger = function (Request $req, callable $next) {
    $start = microtime(true);
    $res = $next($req);
    return $res?->withHeader('X-Time', (string)(microtime(true) - $start));
};

$auth = fn(Request $req, callable $next) => $req->header('x-token') === 'rahasia'
    ? $next($req)
    : Response::json(['error' => 'unauthorized'], 401);

$app->use($logger);                     // semua request, termasuk 404
$app->use('/admin', $auth);             // satu prefix path
$app->get('/me', $auth, $handler);      // satu route

$app->group('/api', $auth, function (App $api) {
    $api->get('/orders', fn() => []);
});
```

## PSR-7

`Kuman\Request` mengimplementasikan `Psr\Http\Message\ServerRequestInterface` dan
`Kuman\Response` mengimplementasikan `ResponseInterface`, jadi kode PSR-7 dan
pustaka middleware bisa dipakai. Keduanya immutable: setiap `with*()` mengembalikan
salinan.

```php
$app->use(function (Request $req, callable $next) {
    $res = $next($req->withAttribute('mulai', microtime(true)));
    return $res?->withHeader('X-Time', (string)(microtime(true) - $req->getAttribute('mulai')));
});

$app->post('/upload', function (Request $req) {
    $req->getUploadedFiles()['avatar']->moveTo('/srv/avatars/me.png');
    return (new Response())->withStatus(201)->withBody(Stream::from('tersimpan'));
});
```

- Handler boleh mengembalikan response PSR-7 apa pun, dari Kuman maupun pustaka
  lain. Body-nya dibaca saat response dikirim.
- Salinan request tetap selaras dengan accessor Kuman: setelah `withUri()`,
  `withRequestTarget()`, `withMethod()`, `withHeader()`, atau `withQueryParams()`,
  router serta `path()`, `method()`, `header()`, dan `get()` melihat nilai barunya.
  `post()`, `file()`, `input()`, dan `all()` tetap membaca body yang diterima, jadi
  pakai `getParsedBody()` dan `getUploadedFiles()` setelah `withParsedBody()` atau
  `withUploadedFiles()`. Pengecualiannya `withBody()`: ia mengganti body untuk
  keduanya.
- `Request::current()` dan `$req->connection` milik request yang diterima Kuman,
  bukan salinannya. `respond()` boleh dipanggil dari request asli maupun salinan.
- Header response divalidasi: nama dengan spasi atau nilai dengan baris baru
  melempar `InvalidArgumentException`, yang menjadi 500.
- Nama header request datang dalam huruf kecil dari Workerman, dan header yang
  berulang sudah digabung dengan koma menjadi satu nilai.
- Ganti `header()` Workerman yang mengubah objek pada response dengan
  `withHeader()`, dan `cookie()` dengan `withCookie()`.

## WebSocket

```php
$app->ws('/chat', [
    'open' => fn($conn, Request $req) => $conn->send('halo'),
    'message' => fn($conn, string $data) => $conn->send($data),
    'close' => fn($conn) => null,
]);

$app->ws('/private', $auth, ['message' => $onMessage]);   // middleware menjaga upgrade
```

## Server-Sent Events

Timer dari `$sse->every()` berhenti otomatis saat klien terputus.

```php
$app->sse('/time', function (Request $req, Sse $sse) {
    $sse->send('tersambung', event: 'hello');
    $sse->every(1, fn() => $sse->send(['time' => date('c')]));
    $sse->onClose(fn() => null);
});
```

## Timer dan state

Closure di `app.php` dijalankan sekali per worker setelah fork, jadi tempat yang
tepat untuk membuka koneksi database dan menyimpan state. Nilainya bertahan antar
request, tapi hanya di worker itu.

```php
return function (App $app) {
    $db = new PDO($dsn);        // satu koneksi per worker

    $app->every(60, fn() => $db->query('SELECT 1'));   // hanya di worker pertama
};
```

Exception di request dan callback bawaan Kuman dicatat tanpa mematikan worker.
Bungkus callback lain, misalnya `Timer::add()` langsung, dengan `$app->guard($fn)`.
Tampilan error bisa diganti dengan `$app->onError()` dan `$app->notFound()`.

## File statis

File di `public/` disajikan otomatis saat tidak ada route yang cocok, lengkap
dengan `Range` dan `If-Modified-Since`. File `.php` dan dotfile tidak pernah
disajikan, kecuali `.well-known/`.

## Konfigurasi

Default sudah cukup untuk mulai. Urutan prioritas, dari terlemah: `kuman.php`, env,
lalu flag.

| Pengaturan | Flag | Env | Default |
|---|---|---|---|
| Port | `--port` | `PORT` | `8642` |
| Alamat | `--host` | `KUMAN_HOST` | `0.0.0.0` |
| Jumlah worker | `--workers` | `WORKERS` | jumlah CPU |
| Event loop | `--loop` | `KUMAN_LOOP` | otomatis |
| Detail error di response | `--debug` | `KUMAN_DEBUG` | mati |
| Reload otomatis | | `KUMAN_WATCH` | aktif di foreground |
| File aplikasi | `--app` | | `app.php` |

`kuman.php` bersifat opsional, dan diperlukan untuk proses tambahan:

```php
<?php

return [
    'port' => 8642,
    'memory_limit' => 256,      // MB, worker di atas batas ini didaur ulang
    'processes' => [
        'queue' => ['handler' => App\QueueWorker::class, 'count' => 2],
    ],
];
```

Handler adalah nama class yang dibuat setelah fork, dan boleh punya method
`onWorkerStart`, `onMessage`, `onClose`, serta callback Workerman lainnya. Isi
`listen` jika proses perlu membuka port sendiri. Perubahan pada `kuman.php` butuh
`kuman restart`.

## Perintah

```bash
vendor/bin/kuman              # start di foreground
vendor/bin/kuman start -d     # daemon
vendor/bin/kuman reload       # muat ulang kode
vendor/bin/kuman stop
vendor/bin/kuman status
vendor/bin/kuman connections
```

## Hal yang perlu diingat

Kuman menjalankan kode di proses yang terus hidup, berbeda dari PHP-FPM.

- **Kode blocking menahan worker.** Query PDO atau `sleep()` membuat satu worker
  melayani satu request pada satu waktu, jadi `WORKERS` adalah kapasitas utama.
- **Fiber hanya membantu kode async.** `--loop=fiber` butuh `revolt/event-loop` dan
  membuat `Timer::sleep()` tidak memblokir, tapi tidak mengubah PDO atau curl biasa.
- **State tidak dibagi antar worker.** Broadcast WebSocket ke semua worker butuh
  pub/sub seperti Redis.
- **Reload memutus koneksi panjang.** Klien WebSocket dan SSE harus reconnect.
- **Pasang `ext-event` di produksi.** Tanpanya loop `select()` dibatasi 1024 koneksi.
- **`exit()` dan `die()` mengakhiri worker,** bukan hanya request.

## Tes

```bash
composer test
```

Tes menjalankan server sungguhan di port 18642, pada loop default dan fiber.

## Lisensi

MIT. Sebagian kode diadaptasi dari [webman](https://github.com/walkor/webman).

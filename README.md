# Kuman

Microframework PHP untuk proses long-running, dibangun langsung di atas
[Workerman](https://github.com/walkor/workerman) dengan routing ala Express.
Satu file route, tanpa folder `config/`, dan HTTP, WebSocket, serta SSE berjalan
di satu port.

Butuh PHP 8.2+ dengan ekstensi `pcntl` dan `posix` (Linux atau macOS).

## Mulai

```bash
composer require kuman/kuman
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

## Handler dan response

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
    return $res?->header('X-Time', (string)(microtime(true) - $start));
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

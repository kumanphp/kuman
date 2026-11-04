<p align="center">
  <img src="https://image.web.id/images/logo-kuman.png" alt="Logo Kuman" width="160">
</p>

# Kuman

[English](../../README.md)

Kuman adalah microframework PHP untuk aplikasi yang berjalan terus-menerus
(long-running). Kuman dibangun langsung di atas
[Workerman](https://github.com/walkor/workerman), dan cara menulis route-nya mirip
Express. Semua route ditulis di satu file, tidak ada folder `config/`, dan HTTP,
WebSocket, serta SSE bisa dilayani dari port yang sama.

Kuman membutuhkan PHP 8.2 atau lebih baru dengan ekstensi `pcntl` dan `posix`, dan
berjalan di Linux atau macOS.

## Mulai

Cara paling cepat adalah membuat proyek baru dari
[skeleton](https://github.com/kumanphp/app):

```bash
composer create-project kumanphp/app myapp
cd myapp
vendor/bin/kuman
```

Setelah itu buka <http://localhost:8642>. Route aplikasi Anda ditulis di `app.php`.

### Menambahkan Kuman ke proyek yang sudah ada

Pasang paketnya dengan Composer:

```bash
composer require kumanphp/kuman
```

Lalu buat file `app.php`:

```php
<?php

use Kuman\App;
use Kuman\Request;

return function (App $app) {
    $app->get('/', fn() => 'Halo dari Kuman');
    $app->get('/users/:id', fn(Request $req) => ['id' => $req->param('id')]);
};
```

Jalankan server, lalu buka <http://localhost:8642>:

```bash
vendor/bin/kuman
```

Selama berjalan di foreground, Kuman otomatis me-reload worker setiap kali ada file
PHP yang berubah.

## Routing

```php
$app->get('/users/:id', $handler);          // tersedia juga post, put, patch, delete, options
$app->all('/any', $handler);                // semua method
$app->route(['GET', 'POST'], '/form', $handler);

$app->get('/users/:id(\d+)', $handler);     // parameter dengan regex
$app->get('/files/*', $handler);            // $req->param('wildcard')
```

Route statis harus didaftarkan lebih dulu daripada route dinamis yang juga cocok
dengan path-nya. Contohnya, daftarkan `/users/me` sebelum `/users/:id`. Kalau
urutannya terbalik, worker gagal start dan terus mencoba restart. Pesan error-nya
muncul di terminal atau di `runtime/kuman.log`.

Route dengan prefix yang sama bisa dikelompokkan dengan `group()`. Argumen terakhir
adalah fungsi yang berisi daftar route. Argumen di antara prefix dan fungsi itu adalah
middleware yang berlaku untuk semua route di dalam grup. Grup bisa dibuat di dalam
grup lain. Jika Anda memanggil `use()` di dalam grup, middleware tersebut hanya berlaku
untuk route di grup itu yang didaftarkan setelahnya.

```php
$app->group('/api', $auth, function (App $api) {
    $api->get('/orders', $handler);                  // GET /api/orders
    $api->group('/v1', function (App $v1) {
        $v1->get('/orders', $handler);               // GET /api/v1/orders
    });
});
```

### File route

Jika `app.php` mulai panjang, pindahkan route ke beberapa file terpisah dan muat
dengan `load()`. Path relatif dihitung dari folder file yang memanggil `load()`. Pola
seperti `routes/*.php` memuat semua file yang cocok, diurutkan berdasarkan nama. Isi
subfolder tidak ikut dimuat.

```php
// app.php
return function (App $app) {
    $app->provide(PDO::class, new PDO($dsn));

    $app->load('routes/web.php', 'routes/api/*.php');
    $app->group('/admin', $auth, fn(App $admin) => $admin->load('routes/admin.php'));
};
```

File route mengembalikan fungsi yang menerima `App`, sama seperti `app.php`. Anda juga
bisa langsung memakai `$app` tanpa membuat fungsi:

```php
// routes/admin.php
return function (App $app) {
    $app->get('/users', [AdminController::class, 'users']);    // GET /admin/users
};

// routes/web.php
$app->get('/', fn() => 'Beranda');
```

File yang dimuat di dalam grup ikut memakai prefix dan middleware grup tersebut. Worker
langsung berhenti saat start jika file tidak ditemukan, jika pola tidak cocok dengan
file apa pun, atau jika file mengembalikan sesuatu selain fungsi. File route dimuat di
setiap worker, jadi mengubah file route akan me-reload worker seperti file lainnya.

### Controller

Selain closure, handler bisa berupa method di sebuah controller, atau kelas yang punya
method `__invoke()`. Middleware route, yaitu argumen sebelum handler terakhir, juga bisa
berupa method controller.

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
        return ['id' => $req->param('id')];          // sama seperti handler biasa
    }
}
```

Kuman membuat objek controller baru untuk setiap request. Artinya, nilai yang disimpan
di properti controller tidak terbawa ke request berikutnya. Parameter constructor
controller diisi otomatis dengan cara yang sama seperti parameter handler (lihat
[Validasi dan injeksi](#validasi-dan-injeksi)). Lain halnya jika Anda memberikan objek
yang sudah dibuat, misalnya `[$controller, 'show']`. Objek itu dipakai terus, jadi isi
propertinya tetap tersimpan dari satu request ke request berikutnya.

Kalau kelas atau method-nya tidak ada, atau method-nya private, worker langsung
berhenti saat start, sama seperti kesalahan routing. Dengan begitu kesalahan ini
ketahuan sejak awal dan tidak baru muncul saat ada request masuk.

Route WebSocket (`ws()`) tidak bisa memakai controller dan tetap memakai callback.

## Handler dan response

Daftar lengkap method `Request` dan `Response`, beserta contoh dan tutorial langkah
demi langkah, ada di [request-response.md](request-response.md).

Handler menerima objek `Kuman\Request` dan harus mengembalikan response. Jenis
response ditentukan dari nilai yang dikembalikan:

```php
fn() => 'teks';                        // HTML
fn() => ['ok' => true];                // JSON
fn() => Response::json($data, 201);
fn() => Response::text('teks', 400);
fn() => Response::redirect('/login');
fn(Request $req) => Response::file('/path/laporan.pdf', $req);
```

Method yang bisa dipakai di request antara lain `param()`, `get()`, `post()`,
`input()`, `all()`, `header()`, `cookie()`, `file()`, `ip()`, `user()`, dan properti
`connection`. Body berformat JSON dibaca otomatis, dan isinya bisa diambil lewat
`post()`. Jika sebuah kode tidak menerima `$req` sebagai parameter, ambil request yang
sedang diproses dengan `Request::current()`.

Jika handler punya parameter kedua, Kuman juga mengirim objek `Response` kosong dan
parameter route. Cara ini sama seperti di Slim:

```php
$app->get('/hello/{name}', function ($request, $response, array $args) {
    $response->getBody()->write("Halo, {$args['name']}");
    return $response->withStatus(201);
});
```

Penulisan `{name}` dan `:name` sama artinya, dan `$args` berisi nilai yang sama dengan
`$req->params`.

Perhatikan urutannya. Tulis isi body terlebih dulu, baru panggil `withStatus()` atau
`withHeader()`, karena kedua method itu membuat salinan response. Kalau Anda menulis
body setelah membuat salinan, tulis ke salinan itu dan kembalikan salinan tersebut.

Handler dengan satu parameter atau tanpa parameter tetap dipanggil seperti biasa.
Hanya handler terakhir di sebuah route yang mendapat parameter tambahan ini.
Middleware route tetap menerima `($request, $next)`.

Handler harus selalu mengembalikan response dengan `return`. Nilai `null` punya arti
khusus, yaitu handler akan mengirim response sendiri nanti lewat `$req->respond()`.
Karena itu, handler yang lupa menulis `return` akan membuat klien menunggu terus.

Contoh response yang baru dikirim dua detik kemudian:

```php
$app->get('/lambat', function (Request $req) use ($app) {
    $app->after(2, fn() => $req->respond(['selesai' => true]));
    return null;
});
```

## Middleware

Middleware menerima request dan `$next`. Memanggil `$next($req)` meneruskan request ke
middleware berikutnya atau ke handler, lalu mengembalikan response-nya. Hasilnya bisa
`null` jika koneksi dijawab sendiri oleh handler, misalnya pada WebSocket, SSE, atau
response yang dikirim belakangan. Karena itu, pakai `?->` saat mengubah hasil `$next`.

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
$app->use('/admin', $auth);             // hanya path dengan prefix ini
$app->get('/me', $auth, $handler);      // satu route saja

$app->group('/api', $auth, function (App $api) {
    $api->get('/orders', fn() => []);
});
```

## Autentikasi

Penjelasan lengkap tentang semua guard dan opsinya, termasuk session, kunci JWT, dan
kesalahan yang sering terjadi, ada di [auth.md](auth.md).

`Kuman\Auth` menyediakan guard. Guard adalah middleware yang memeriksa siapa pengirim
request. Jika pemeriksaan berhasil, request diteruskan dan data user-nya bisa dibaca
lewat `$req->user()`. Jika gagal, guard menjawab dengan status 401.

```php
$jwt = new Jwt(getenv('JWT_SECRET'));

$app->use('/admin', Auth::basic(['admin' => $passwordHash]));
$app->use('/hooks', Auth::apiKey([getenv('PARTNER_KEY') => 'partner']));
$app->group('/api', Auth::jwt($jwt), function (App $api) {
    $api->get('/me', fn(Request $req) => $req->user());
});
$app->get('/feed', Auth::bearer($findByToken)->optional(), $handler);
$app->get('/dashboard', Auth::session($loadUser, redirect: '/login'), $handler);
$app->get('/orders', Auth::any(Auth::session($loadUser), Auth::jwt($jwt)), $handler);
```

- `Auth::verify($password, $user?->password)` memeriksa password. Lama prosesnya tetap
  sama walaupun akunnya tidak ada, supaya orang lain tidak bisa menebak akun mana yang
  terdaftar dari lamanya respons. Untuk membuat hash password, pakai `Auth::hash()`.
- `$jwt->issue(['sub' => $id])` membuat token JWT. Algoritma yang didukung adalah HS,
  RS, dan ES.
- `Auth::login($req, $id)` memulai login berbasis session dan membuat session id baru.
  `Auth::logout($req, $response)` mengakhiri login tersebut.
- Kuman tidak membatasi jumlah percobaan login. Pasang rate limiter sendiri di depan
  route login Anda.

## Validasi dan injeksi

Detail tipe data, rule, sumber data, dan format error ada di
[validation.md](validation.md). Penjelasan tentang layanan, nilai per request, dan
`#[Depends]` ada di [injection.md](injection.md).

Handler bisa meminta data yang dibutuhkannya lewat parameter yang diberi tipe.
Parameter bertipe Dto diisi dari data request, lalu divalidasi sebelum handler
dijalankan. Jika ada input yang salah, Kuman menjawab dengan status 422 berisi daftar
semua kesalahannya, dan handler tidak dijalankan.

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

return function (App $app) {
    $app->provide(PDO::class, new PDO($dsn));      // satu objek per worker

    $app->post('/users', fn(CreateUser $user, PDO $db) => createUser($db, $user));
    $app->get('/users/:id', fn(int $id, PDO $db) => findUser($db, $id));
};
```

Kuman memeriksa dari mana setiap parameter akan diisi pada saat worker start. Jika ada
parameter yang tidak bisa diisi, worker langsung berhenti saat itu juga. Handler dengan
bentuk biasa, yaitu `fn(Request $req)` dan `fn($request, $response, array $args)`, tetap
dipanggil seperti biasa.

## Dokumentasi API

Penjelasan tentang attribute, response, dan security scheme ada di
[openapi.md](openapi.md).

```php
$app->enableDocs(title: 'Orders API', version: '1.0.0');   // /docs dan /openapi.json
```

Kuman menyusun dokumen OpenAPI 3.1 dari route, kelas Dto, dan guard yang Anda tulis.
Fitur ini tidak aktif sampai Anda memanggil `enableDocs()`. Di luar lingkungan
development, lindungi halaman docs dengan guard lewat parameter `middleware:`.

## PSR-7

`Kuman\Request` mengimplementasikan `Psr\Http\Message\ServerRequestInterface`, dan
`Kuman\Response` mengimplementasikan `ResponseInterface`. Dengan begitu, kode dan
pustaka middleware yang mengikuti PSR-7 bisa dipakai di Kuman. Kedua objek ini
immutable. Setiap method `with*()` tidak mengubah objek aslinya, tetapi mengembalikan
salinan baru.

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

- Handler boleh mengembalikan response PSR-7 dari pustaka mana pun, tidak harus dari
  Kuman. Body-nya baru dibaca saat response dikirim.
- Perubahan pada salinan request ikut terbaca oleh method bawaan Kuman. Setelah Anda
  memanggil `withUri()`, `withRequestTarget()`, `withMethod()`, `withHeader()`, atau
  `withQueryParams()`, router dan method `path()`, `method()`, `header()`, serta
  `get()` memakai nilai yang baru.
- Hal di atas tidak berlaku untuk `post()`, `file()`, `input()`, dan `all()`.
  Keempatnya tetap membaca body asli dari request. Jadi setelah memakai
  `withParsedBody()` atau `withUploadedFiles()`, baca datanya dengan `getParsedBody()`
  dan `getUploadedFiles()`. Pengecualiannya adalah `withBody()`, yang mengganti body
  untuk kedua kelompok method tersebut.
- `Request::current()` dan `$req->connection` selalu merujuk ke request asli yang
  diterima Kuman, bukan ke salinannya. Pengecualiannya adalah guard autentikasi. Guard
  menjadikan salinan yang sudah berisi data user sebagai request saat ini. `respond()`
  boleh dipanggil dari request asli maupun dari salinannya.
- Header response divalidasi. Nama header yang mengandung spasi atau nilai yang
  mengandung baris baru akan memunculkan `InvalidArgumentException`, dan klien menerima
  status 500.
- Workerman mengirim nama header request dalam huruf kecil. Header yang muncul lebih
  dari sekali sudah digabung menjadi satu nilai yang dipisahkan koma.
- Method `header()` dan `cookie()` milik Workerman mengubah objek response secara
  langsung. Di Kuman, ganti keduanya dengan `withHeader()` dan `withCookie()`.

## WebSocket

Route WebSocket dibuat dengan `ws()`. Callback `open`, `message`, dan `close` dipanggil
saat klien tersambung, mengirim pesan, dan terputus.

```php
$app->ws('/chat', [
    'open' => fn($conn, Request $req) => $conn->send('halo'),
    'message' => fn($conn, string $data) => $conn->send($data),
    'close' => fn($conn) => null,
]);

$app->ws('/private', $auth, ['message' => $onMessage]);   // middleware memeriksa request sebelum koneksi di-upgrade
```

## Server-Sent Events

Route SSE dibuat dengan `sse()`. Timer yang dibuat dengan `$sse->every()` otomatis
berhenti saat klien terputus.

```php
$app->sse('/time', function (Request $req, Sse $sse) {
    $sse->send('tersambung', event: 'hello');
    $sse->every(1, fn() => $sse->send(['time' => date('c')]));
    $sse->onClose(fn() => null);
});
```

## Timer dan state

Fungsi yang dikembalikan oleh `app.php` dijalankan satu kali di setiap worker, setelah
proses worker dibuat (fork). Karena itu, fungsi ini cocok untuk membuka koneksi
database dan menyimpan state. Nilai yang disimpan di sini tetap ada dari satu request
ke request berikutnya, tetapi hanya di worker tersebut. Worker lain punya salinannya
sendiri.

```php
return function (App $app) {
    $db = new PDO($dsn);        // satu koneksi per worker

    $app->every(60, fn() => $db->query('SELECT 1'));   // hanya berjalan di worker pertama
};
```

Jika terjadi exception saat memproses request atau di dalam callback yang dijalankan
Kuman, exception itu dicatat dan worker tetap berjalan. Untuk callback yang tidak
dijalankan lewat Kuman, misalnya `Timer::add()` yang dipanggil langsung, bungkus
dengan `$app->guard($fn)` agar diperlakukan sama. Tampilan halaman error bisa diganti
dengan `$app->onError()`, dan halaman 404 dengan `$app->notFound()`.

## File statis

Jika tidak ada route yang cocok, Kuman mencari file di folder `public/` dan
mengirimkannya. Header `Range` dan `If-Modified-Since` sudah didukung. File `.php` dan
file yang namanya diawali titik tidak pernah dikirim, kecuali file di dalam
`.well-known/`.

## Konfigurasi

Anda bisa langsung mulai tanpa mengubah pengaturan apa pun. Pengaturan bisa diisi di
tiga tempat, yaitu `kuman.php`, environment variable, dan flag di command line. Jika
pengaturan yang sama diisi di beberapa tempat, nilai dari flag yang dipakai. Tanpa
flag, nilai dari env yang dipakai. `kuman.php` hanya dipakai jika keduanya tidak diisi.

| Pengaturan | Flag | Env | Default |
|---|---|---|---|
| Port | `--port` | `PORT` | `8642` |
| Alamat | `--host` | `KUMAN_HOST` | `0.0.0.0` |
| Jumlah worker | `--workers` | `WORKERS` | jumlah CPU |
| Event loop | `--loop` | `KUMAN_LOOP` | otomatis |
| Detail error di response | `--debug` | `KUMAN_DEBUG` | mati |
| Reload otomatis | | `KUMAN_WATCH` | aktif saat di foreground |
| File aplikasi | `--app` | | `app.php` |

File `kuman.php` tidak wajib ada. File ini baru diperlukan jika Anda ingin menjalankan
proses tambahan:

```php
<?php

return [
    'port' => 8642,
    'memory_limit' => 256,      // MB, worker yang memakai memori di atas batas ini akan di-restart
    'processes' => [
        'queue' => ['handler' => App\QueueWorker::class, 'count' => 2],
    ],
];
```

Nilai `handler` adalah nama kelas. Objek dari kelas ini dibuat setelah fork, dan
kelasnya boleh punya method `onWorkerStart`, `onMessage`, `onClose`, serta callback
Workerman lainnya. Isi `listen` jika proses tersebut perlu membuka port sendiri.
Setelah mengubah `kuman.php`, jalankan `kuman restart` agar perubahannya terbaca.

## Perintah

```bash
vendor/bin/kuman              # jalankan di foreground
vendor/bin/kuman start -d     # jalankan sebagai daemon
vendor/bin/kuman reload       # muat ulang kode
vendor/bin/kuman stop         # hentikan server
vendor/bin/kuman status       # lihat status worker
vendor/bin/kuman connections  # lihat daftar koneksi
```

## Hal yang perlu diperhatikan

Cara kerja Kuman berbeda dari PHP-FPM. Di PHP-FPM, semua variabel dan objek dibuang
setiap kali request selesai. Di Kuman, proses worker terus hidup dan melayani banyak
request. Karena perbedaan ini, ada beberapa hal yang perlu diperhatikan.

- Kode blocking membuat worker tertahan. Selama sebuah worker menjalankan query PDO
  atau `sleep()`, worker itu tidak bisa melayani request lain. Jadi jumlah request yang
  bisa dilayani bersamaan bergantung pada jumlah worker (`WORKERS`).
- Mode fiber hanya membantu kode yang async. `--loop=fiber` membutuhkan paket
  `revolt/event-loop` dan membuat `Timer::sleep()` tidak memblokir worker. PDO dan
  curl biasa tetap memblokir.
- State tidak dibagi antar worker. Jika Anda ingin mengirim pesan WebSocket ke klien
  di semua worker, gunakan pub/sub seperti Redis.
- Reload memutus koneksi yang sedang terbuka. Klien WebSocket dan SSE harus menyambung
  ulang.
- Pasang `ext-event` di server produksi. Tanpa ekstensi ini, Kuman memakai `select()`
  yang hanya bisa menangani 1024 koneksi.
- `exit()` dan `die()` menghentikan seluruh worker, tidak hanya request yang sedang
  diproses.

## Tes

Jalankan tes dengan perintah berikut:

```bash
composer test
```

Tes ini menjalankan server sungguhan di port 18642, dengan event loop default dan
dengan fiber.

## Lisensi

MIT. Sebagian kode diambil dan disesuaikan dari
[webman](https://github.com/walkor/webman).

# Autentikasi

`Kuman\Auth` membuktikan siapa yang mengirim request. Setiap method-nya membuat sebuah
**guard**, yaitu middleware biasa: guard meloloskan request dan mencatat user-nya, atau
menjawab 401. Ini hanya mencakup autentikasi. Menentukan apa yang boleh dilakukan user
adalah urusan aplikasi Anda.

- [Mulai cepat](#mulai-cepat)
- [Memilih guard](#memilih-guard)
- [Memasang guard](#memasang-guard)
- [Membaca user](#membaca-user)
- [HTTP Basic](#http-basic)
- [Bearer token](#bearer-token)
- [API key](#api-key)
- [JSON Web Token](#json-web-token)
- [Session](#session)
- [Menggabungkan guard](#menggabungkan-guard)
- [Password dan token](#password-dan-token)
- [Hal-hal yang sering menjebak](#hal-hal-yang-sering-menjebak)

## Mulai cepat

```php
use Kuman\App;
use Kuman\Auth;
use Kuman\Auth\Jwt;
use Kuman\Request;
use Kuman\Response;

return function (App $app) {
    $jwt = new Jwt(getenv('JWT_SECRET'));       // minimal 32 byte

    $app->post('/login', function (Request $req) use ($jwt) {
        $user = findUser($req->post('email'));  // pencarian milik Anda
        if (!Auth::verify($req->post('password'), $user?->password)) {
            return Response::json(['error' => 'invalid credentials'], 401);
        }
        return ['token' => $jwt->issue(['sub' => $user->id])];
    });

    $app->group('/api', Auth::jwt($jwt), function (App $api) {
        $api->get('/me', fn(Request $req) => $req->user());
    });
};
```

## Memilih guard

| Guard | Yang dikirim klien | Cocok untuk |
|---|---|---|
| `Auth::basic()` | `Authorization: Basic ...` | Alat internal dan staging, yang cukup memakai dialog bawaan browser |
| `Auth::bearer()` | `Authorization: Bearer ...` | Token opaque yang Anda simpan dan bisa dicabut |
| `Auth::apiKey()` | `X-API-Key: ...` | Panggilan antar-server |
| `Auth::jwt()` | `Authorization: Bearer <jwt>` | API yang memverifikasi token tanpa pencarian, atau token dari identity provider |
| `Auth::session()` | Cookie session | Aplikasi browser dengan form login |
| `Auth::any()` | Salah satu di atas | Satu route yang dipakai browser dan klien API |

Layani semuanya lewat HTTPS: password Basic dan token bisa terbaca di jaringan.

## Memasang guard

Guard adalah callable, jadi bisa dipasang di mana pun middleware dipasang.

```php
$guard = Auth::bearer(fn(string $token) => findByToken($token));

$app->use('/admin', $guard);                         // satu prefix path, termasuk file statis
$app->get('/me', $guard, $handler);                  // satu route
$app->group('/api', $guard, function (App $api) {    // satu grup
    $api->get('/orders', $handler);
});
$app->ws('/chat', $guard, ['message' => $onMessage]);   // menjaga upgrade
$app->sse('/events', $guard, $handler);
```

Hanya `$app->use()` di luar grup yang melindungi file di `public/`. Middleware route dan
grup berjalan setelah route cocok, sedangkan file statis tidak punya route.

Satu guard boleh dipakai bersama oleh banyak route. `optional()` dan `onDeny()`
mengembalikan salinan yang sudah diubah, dan guard asalnya tetap seperti semula.

**Login opsional.** Route juga menjawab request anonim, dan `$req->user()` bernilai `null`
untuk request itu:

```php
$app->get('/feed', $guard->optional(), function (Request $req) {
    return $req->user() ? personalFeed($req->user()) : publicFeed();
});
```

**Penolakan khusus.** Handler menerima request dan response bawaan, yang sudah membawa
header `WWW-Authenticate`:

```php
$guard = $guard->onDeny(fn(Request $req, Response $default) => $default->withBody(Stream::from('Siapa Anda?')));
```

## Membaca user

```php
$req->user();                        // hasil dari guard, null kalau tidak ada guard yang berjalan
Request::current()->user();          // untuk kode yang tidak diberi $req
$req->getAttribute('user');          // nilai yang sama, lewat PSR-7
```

User adalah apa pun yang dikembalikan callback Anda: baris data, objek, atau id. Di
handler `open` WebSocket dan handler SSE, pakai `$req` yang mereka terima.

## HTTP Basic

Beri callback, atau peta username ke hash `password_hash()`. Password polos di dalam peta
menghentikan worker saat start.

```php
$app->use('/admin', Auth::basic(['admin' => getenv('ADMIN_HASH')], realm: 'Admin'));

$app->use('/admin', Auth::basic(function (string $username, string $password) {
    $user = findUser($username);
    return Auth::verify($password, $user?->password) ? $user : null;
}));
```

Callback mengembalikan user, atau `null` atau `false` untuk menolak. Kalau mengembalikan
`true`, username dipakai sebagai user.

Basic memeriksa password pada **setiap request**. `password_verify()` memang dibuat lambat,
puluhan sampai ratusan milidetik, dan selama itu worker terblokir. Untuk trafik tinggi,
login sekali lalu pakai token atau session.

## Bearer token

```php
Auth::bearer(fn(string $token) => findByTokenHash(hash('sha256', $token)));

Auth::bearer([getenv('CI_TOKEN') => 'ci', getenv('DEPLOY_TOKEN') => 'deploy']);
```

Peta dibandingkan dalam waktu konstan. Key yang lebih pendek dari 16 karakter menghentikan
worker saat start, sehingga environment variable yang belum diisi juga ketahuan.

Browser tidak bisa mengatur header pada `WebSocket` dan `EventSource`. Sebutkan satu query
parameter supaya token juga diterima di sana:

```php
$app->ws('/chat', Auth::bearer($tokens, query: 'token'), ['message' => $onMessage]);
```

URL masuk ke access log dan riwayat browser, jadi beri klien seperti itu token berumur
pendek. Cookie session juga bisa dipakai oleh klien ini, tanpa semua hal di atas.

## API key

Sama seperti bearer token, tetapi dibaca dari header sendiri:

```php
Auth::apiKey(fn(string $key) => findByKeyHash(hash('sha256', $key)));
Auth::apiKey([getenv('PARTNER_KEY') => 'partner'], header: 'X-Partner-Key');
Auth::apiKey($keys, query: 'api_key');                    // juga ?api_key=...
```

## JSON Web Token

`Jwt` menerbitkan dan memverifikasi token, sedangkan `Auth::jwt()` membacanya dari header
`Authorization`.

```php
$jwt = new Jwt(
    getenv('JWT_SECRET'),
    algorithm: 'HS256',
    ttl: 3600,                 // detik, null berarti tidak kedaluwarsa
    issuer: 'my-app',          // ditulis ke token dan diwajibkan ada di token
    audience: 'my-api',        // sama
    leeway: 30,                // detik selisih jam yang ditoleransi
);

$token = $jwt->issue(['sub' => $user->id, 'role' => $user->role]);
$claims = $jwt->verify($token);                   // array, atau null kalau ada yang salah

$app->use('/api', Auth::jwt($jwt));               // user adalah array claims
$app->use('/api', Auth::jwt($jwt, fn(array $claims) => findUser($claims['sub'])));
```

| Algoritma | Kunci | Aturan |
|---|---|---|
| `HS256`, `HS384`, `HS512` | Secret bersama | Minimal 32, 48, atau 64 byte |
| `RS256`, `RS384`, `RS512` | Kunci RSA dalam PEM, perlu `ext-openssl` | Minimal 2048 bit |
| `ES256`, `ES384` | Kunci P-256 atau P-384 dalam PEM, perlu `ext-openssl` | Kurvanya harus cocok |

Kunci yang melanggar aturan ini menghentikan worker saat start. Untuk RS dan ES, berikan
public key ke layanan yang hanya memverifikasi: layanan itu bisa memeriksa token, tetapi
`issue()` melempar exception.

`verify()` mengambil algoritma dari konfigurasi Anda dan menolak token yang menyebut
algoritma lain, termasuk `none`. Token juga ditolak kalau `exp` sudah lewat, `nbf` masih di
masa depan, `iss` dan `aud` hilang atau berbeda padahal Anda mengonfigurasinya, atau ada
header `crit`. Token tanpa `exp` diterima, karena standarnya memang menjadikannya opsional.

Claims ditandatangani, bukan dienkripsi, jadi siapa pun yang memegang token bisa
membacanya. JWT juga tidak bisa dicabut sebelum `exp`. Buat `ttl` pendek, atau periksa
sesuatu seperti claim `jti` terhadap daftar di dalam callback, walaupun itu mengembalikan
pencarian yang biasanya dihindari JWT.

## Session

Periksa password sendiri, lalu panggil `Auth::login()` dengan id. Session tidak
memulihkan objek, jadi simpan id-nya dan muat user di callback.

```php
$app->post('/login', function (Request $req) {
    $user = findUser($req->post('email'));
    if (!Auth::verify($req->post('password'), $user?->password)) {
        return Response::redirect('/login?failed=1');
    }
    Auth::login($req, $user->id);
    return Response::redirect('/dashboard');
});

$app->post('/logout', fn(Request $req) => Auth::logout($req, Response::redirect('/login')));

$pages = Auth::session(fn(int|string $id) => findUser($id), redirect: '/login');
$app->group('/dashboard', $pages, function (App $dashboard) {
    $dashboard->get('/', fn(Request $req) => 'Halo ' . $req->user()->name);
});
```

Tanpa `redirect`, request yang ditolak mendapat 401. `Auth::login()` membuat session id
baru, sehingga id yang ditanam di browser sebelum login tidak berguna, dan isi session
lainnya tetap ada. `Auth::logout()` menghapus session, lalu mengembalikan response yang
Anda berikan dengan cookie-nya dihapus. Kembalikan response itu.

Kuman mengatur default session berikut saat worker start, dan `app.php` bisa mengubahnya
lewat properti statis `Workerman\Protocols\Http\Session`:

| Pengaturan | Default | Cara mengubah |
|---|---|---|
| Penyimpanan | File di `runtime/sessions/`, hanya bisa dibaca pemiliknya | `Session::handlerClass()` |
| Flag cookie | `HttpOnly`, `SameSite=Lax` | `Session::$httpOnly`, `Session::$sameSite` |
| Flag `Secure` | Mati, supaya HTTP biasa tetap jalan saat development | `Session::$secure = true` |
| Batas waktu idle | `session.gc_maxlifetime` dari `php.ini`, biasanya 24 menit, diperbarui setiap request | `Session::$lifetime` |

Nyalakan `Secure` di production:

```php
use Workerman\Protocols\Http\Session;

return function (App $app) {
    Session::$secure = true;
    Session::$lifetime = 7200;
    // ...
};
```

File berfungsi lintas worker di satu mesin. Untuk beberapa mesin, pakai Redis:

```php
use Workerman\Protocols\Http\Session\RedisSessionHandler;

Session::handlerClass(RedisSessionHandler::class, ['host' => '127.0.0.1', 'port' => 6379]);
```

## Menggabungkan guard

`Auth::any()` mencoba setiap guard berurutan, dan guard pertama yang mengenali request
menang. Request yang ditolak mendapat jawaban dari guard pertama.

```php
$app->group('/api', Auth::any(Auth::session($load), Auth::bearer($tokens)), function (App $api) {
    $api->get('/orders', $handler);
});
```

## Password dan token

```php
$hash = Auth::hash($password);              // password_hash() dengan algoritma default PHP
Auth::verify($password, $hash);             // true atau false
Auth::verify($password, null);              // false, setelah kerja yang sama banyaknya
```

Berikan `null` untuk akun yang tidak ada, seperti contoh di atas dengan `$user?->password`.
Pemeriksaannya jadi selama pemeriksaan sungguhan, sehingga waktu respons tidak membocorkan
akun mana yang ada. `verify()` juga menerima nilai apa pun, karena `$req->post('password')`
berupa array kalau klien mengirim `password[]=x`.

`Auth::token()` mengembalikan 64 karakter hex acak untuk API key dan sejenisnya. Tunjukkan
sekali ke user dan simpan hanya `hash('sha256', $token)`, supaya database yang bocor tidak
berisi token yang bisa dipakai.

## Hal-hal yang sering menjebak

- **Percobaan login tidak dibatasi.** Kuman tidak punya rate limiter. Pasang di depan route
  login, yang dipakai bersama oleh semua worker, misalnya di Redis, atau di reverse proxy.
- **Cookie perlu memikirkan CSRF.** `SameSite=Lax` mencegah situs lain mengirim request
  `POST` dengan cookie session Anda. Itu tidak mencakup `GET` yang mengubah sesuatu, jadi
  letakkan perubahan di balik `POST`, `PUT`, dan `DELETE`.
- **Callback berjalan di setiap request dan memblokir worker.** Query database di guard
  menahan worker seperti query PDO lainnya.
- **Preflight CORS tidak membawa kredensial.** Request `OPTIONS` di bawah prefix yang dijaga
  mendapat 401, jadi middleware CORS harus dipasang sebelum guard.
- **Pakai `$req` yang diberikan.** Guard meneruskan salinan yang membawa user. Variabel yang
  ditangkap lebih awal, atau request yang dipegang middleware dari sebelum guard berjalan,
  tidak punya user. `Request::current()` mengikuti guard, tetapi hanya ada selama request
  berlangsung, tidak di timer yang berjalan belakangan.

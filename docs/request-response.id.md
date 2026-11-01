# Request dan response

Referensi `Kuman\Request` dan `Kuman\Response`, disusul tutorial yang membangun API
kecil dengan keduanya. Semua contoh di sini sudah dijalankan di Kuman.

- [Yang diterima dan dikembalikan handler](#yang-diterima-dan-dikembalikan-handler)
- [Referensi Request](#referensi-request)
- [Referensi Response](#referensi-response)
- [File upload, stream, dan URI](#file-upload-stream-dan-uri)
- [Tutorial: API catatan](#tutorial-api-catatan)
- [Hal-hal yang sering menjebak](#hal-hal-yang-sering-menjebak)

Kuman mengimplementasikan [PSR-7](https://www.php-fig.org/psr/psr-7/) di kedua kelas.
Anda bisa memakai accessor Kuman yang singkat (`$req->get('q')`), method PSR-7
(`$req->getQueryParams()['q']`), atau mencampurnya. Tabel di bawah menampilkan
keduanya.

## Yang diterima dan dikembalikan handler

Handler menerima request, dan **mengembalikan** apa yang dikirim:

```php
fn(Request $req) => 'teks';                  // 200, text/html
fn(Request $req) => ['ok' => true];          // 200, application/json
fn(Request $req) => Response::json($d, 201); // persis yang Anda bangun
fn(Request $req) => null;                    // handler memegang koneksi
```

| Nilai yang dikembalikan | Hasil |
|---|---|
| `string` atau `Stringable` | HTML, status 200 |
| array, objek, angka, bool | JSON, status 200 |
| `Kuman\Response` | dikirim apa adanya |
| `ResponseInterface` PSR-7 apa pun | dikonversi, body dibaca saat dikirim |
| `null` | belum ada yang dikirim, lihat [Request::respond()](#tambahan-kuman) |

Handler yang mendeklarasikan **parameter kedua** juga menerima `Response` kosong untuk
diisi dan parameter route, seperti di Slim:

```php
$app->get('/hello/{name}', function ($request, $response, array $args) {
    $response->getBody()->write("Halo, {$args['name']}");
    return $response->withHeader('X-Greeted', 'yes');
});
```

Hanya handler terakhir sebuah route yang mendapat ini. Middleware route dan global
menerima `($request, $next)`. Method controller bekerja sama:
`[NoteController::class, 'show']`.

## Referensi Request

### Membaca input

| Method | Mengembalikan |
|---|---|
| `get()` | Semua nilai query string, sebagai array |
| `get('q', $default)` | Satu nilai query string |
| `post()` | Semua nilai body (form atau JSON), sebagai array |
| `post('nama', $default)` | Satu nilai body |
| `input('nama', $default)` | Nilai body, kalau tidak ada nilai query, kalau tidak ada nilai default |
| `all()` | Nilai query dan body digabung, body menang |
| `param('id', $default)` | Satu parameter route |
| `header('x-token', $default)` | Satu header, nama tidak peka huruf besar-kecil |
| `header()` | Semua header, dengan nama huruf kecil |
| `cookie('session', $default)` | Satu cookie |
| `cookie()` | Semua cookie |
| `file('avatar')` | Entri upload mentah, lihat [upload](#file-upload-stream-dan-uri) |

```php
// POST /search?page=2   body: {"q": "php", "page": 5}
$app->post('/search', function (Request $req) {
    return [
        'query' => $req->get(),                  // ['page' => '2']
        'body'  => $req->post(),                 // ['q' => 'php', 'page' => 5]
        'q'     => $req->input('q'),             // 'php'
        'page'  => $req->input('page'),          // 5, body menang atas query
        'all'   => $req->all(),                  // ['page' => 5, 'q' => 'php']
        'limit' => $req->input('limit', 20),     // 20, nilai default
    ];
});
```

Body JSON diparse ketika `Content-Type` mengandung `json`, dan dibaca lewat `post()`
seperti form. Nilai query berupa string, nilai JSON mempertahankan tipenya. Nama
dengan kurung menjadi array: `?tag[]=a&tag[]=b` menghasilkan `['a', 'b']`.

### Parameter route

`/users/:id` dan `/users/{id}` sama saja. Parameter di-URL-decode.

```php
$app->get('/users/:id', fn(Request $req) => ['id' => $req->param('id')]);
$app->get('/digits/:id(\d+)', $handler);        // dengan regex
$app->get('/files/*', fn(Request $req) => $req->param('wildcard'));   // /files/a/b.txt: 'a/b.txt'
```

`$req->params` adalah seluruh array-nya, dan itulah yang diterima handler gaya Slim
sebagai `$args`.

### Request line dan klien

| Method | Contoh hasil untuk `GET /users/7?x=1` |
|---|---|
| `method()` | `'GET'` |
| `path()` | `'/users/7'`, seperti yang dikirim, tidak di-decode |
| `queryString()` | `'x=1'` |
| `uri()` | `'/users/7?x=1'` |
| `host()` | `'example.com:8080'` |
| `host(true)` | `'example.com'`, tanpa port |
| `protocolVersion()` | `'1.1'` |
| `ip()` | Alamat klien, `'203.0.113.9'` |
| `rawBody()` | Body persis seperti diterima, sebagai string |
| `rawHead()` | Request line dan header |

`ip()` adalah alamat peer TCP. Di belakang proxy itu adalah proxy-nya, jadi baca
`X-Forwarded-For` sendiri, dan percayai hanya kalau Anda tahu proxy yang
mengisinya.

### Tambahan Kuman

| Anggota | Isinya |
|---|---|
| `Request::current()` | Request yang sedang ditangani di fiber ini, untuk kode yang tidak diberi `$req` |
| `$req->respond($value)` | Mengirim response belakangan, setelah handler mengembalikan `null` |
| `$req->connection` | Koneksi Workerman |
| `$req->params` | Parameter route |

```php
$app->get('/lambat', function (Request $req) use ($app) {
    $app->after(2, fn() => $req->respond(['done' => true]));
    return null;                    // pegang koneksi, jawab dua detik lagi
});
```

### Tampilan PSR-7

`Request` mengimplementasikan `ServerRequestInterface`. Tiap accessor Kuman punya padanan PSR-7:

| Kuman | PSR-7 |
|---|---|
| `method()` | `getMethod()` |
| `uri()` | `getRequestTarget()` |
| `path()`, `queryString()` | `getUri()->getPath()`, `getUri()->getQuery()` |
| `host()` | `getUri()->getHost()`, `getHeaderLine('Host')` |
| `get()` | `getQueryParams()` |
| `post()` | `getParsedBody()`, yang `null` untuk body kosong atau tipe yang bukan form atau JSON |
| `header('x')` | `getHeaderLine('x')`, `getHeader('x')` (berupa daftar), `hasHeader('x')`, `getHeaders()` |
| `cookie()` | `getCookieParams()` |
| `file()` | `getUploadedFiles()` |
| `rawBody()` | `(string)getBody()`, atau `getBody()->read()` |
| `ip()` | `getServerParams()['REMOTE_ADDR']` |
| `protocolVersion()` | `getProtocolVersion()` |
| `params` | `getAttributes()`, untuk yang disimpan middleware |

`getUri()` membangun URI lengkap dari header `Host`: `http://example.com:8080/users/7?x=1`
(`https` kalau koneksinya TLS). `Host` yang rusak diabaikan dan URI hanya berisi path
dan query. `getServerParams()` berisi `REMOTE_ADDR`, `REMOTE_PORT`, `SERVER_ADDR`,
`SERVER_PORT`, `SERVER_PROTOCOL`, `REQUEST_METHOD`, `REQUEST_URI`, dan `QUERY_STRING`.

### Mengubah request

Request itu immutable. Setiap method `with*()` mengembalikan **salinan** dan tidak
menyentuh yang asli:

| Method | Kegunaan |
|---|---|
| `withAttribute($nama, $nilai)`, `withoutAttribute($nama)` | Mengoper data ke middleware berikutnya dan handler |
| `withHeader()`, `withAddedHeader()`, `withoutHeader()` | Mengubah header |
| `withMethod('PUT')` | Method override |
| `withUri($uri)`, `withRequestTarget('/x?y=1')` | Menulis ulang URL: router dan `path()` mengikutinya |
| `withQueryParams([...])`, `withCookieParams([...])` | Mengganti yang dilaporkan `get()` dan `cookie()` |
| `withParsedBody($data)`, `withUploadedFiles([...])` | Mengganti yang dilaporkan getter PSR-7 |
| `withBody($stream)` | Mengganti body untuk PSR-7 dan `post()` sekaligus |
| `withProtocolVersion('1.0')` | Jarang dibutuhkan |

```php
$app->use(function (Request $req, callable $next) {
    $start = microtime(true);
    $res = $next($req->withAttribute('request-id', bin2hex(random_bytes(4))));
    return $res?->withHeader('X-Time', sprintf('%.1f ms', (microtime(true) - $start) * 1000));
});

// di mana pun sesudahnya: $req->getAttribute('request-id')
```

```php
// Layani /v1/... dari route /api/...
$app->use('/v1', fn(Request $req, callable $next) => $next(
    $req->withUri($req->getUri()->withPath('/api' . substr($req->path(), 3)))
));
```

Dua hal yang perlu diingat:

- Teruskan salinannya: `$next($req->withAttribute(...))`. Salinan yang tidak
  diteruskan hilang begitu saja.
- `withParsedBody()` dan `withUploadedFiles()` hanya mengubah getter PSR-7.
  `post()`, `input()`, `all()`, dan `file()` tetap membaca body yang diterima.

## Referensi Response

Response juga immutable. `with*()` mengembalikan salinan, jadi **pakai nilai
kembaliannya**:

```php
$res->withStatus(404);                  // salah: hasilnya dibuang
return $res->withStatus(404);           // benar
```

### Membuat response

| Panggilan | Hasil |
|---|---|
| `Response::json($data, $status = 200)` | JSON, `application/json` |
| `Response::html($body, $status = 200)` | `text/html; charset=utf-8` |
| `Response::text($body, $status = 200)` | `text/plain; charset=utf-8` |
| `Response::redirect($url, $status = 302)` | Header `Location` tanpa body |
| `Response::file($path, $req)` | File dari disk, lihat [file](#file) |
| `new Response($status, $headers, $body)` | Selebihnya |
| `Response::from($value)` | Jalur yang dilewati nilai kembalian handler |

```php
Response::json(['error' => 'tidak ditemukan'], 404);
Response::redirect('/login', 301);
new Response(204);                                          // tanpa isi
new Response(200, ['X-Id' => '7', 'Vary' => ['A', 'B']], 'body');
```

Constructor-nya `new Response(int $status = 200, array $headers = [], string|StreamInterface $body = '', string $version = '1.1', string $reason = '')`.
Nilai header berupa string atau daftar string. Tanpa header apa pun, response dikirim
sebagai `text/html`.

### Status

```php
$res->withStatus(201);                  // reason phrase: Created
$res->withStatus(418, "I'm a teapot");  // reason phrase sendiri
$res->getStatusCode();                  // 201
$res->getReasonPhrase();                // 'Created'
```

Status di luar 100 sampai 599 melempar `InvalidArgumentException`.

### Header

| Method | Kegunaan |
|---|---|
| `withHeader('X-A', 'v')` | Menetapkan, menggantikan nilai yang ada |
| `withAddedHeader('X-A', 'v')` | Menambah nilai lain |
| `withoutHeader('X-A')` | Menghapus |
| `hasHeader()`, `getHeader()` (daftar), `getHeaderLine()` (digabung), `getHeaders()` | Membaca, nama tidak peka huruf besar-kecil |

```php
$res = Response::json($data)
    ->withHeader('Cache-Control', 'no-store')
    ->withAddedHeader('Vary', 'Accept')
    ->withAddedHeader('Vary', 'Origin');       // terkirim sebagai "Vary: Accept, Origin"
```

Nama header dengan spasi, atau nilai dengan baris baru, melempar
`InvalidArgumentException`; begitulah Kuman menahan header injection. Nama mempertahankan
huruf yang Anda berikan. Header yang berulang digabung dengan koma, kecuali
`Set-Cookie` yang dikirim sebagai baris terpisah.

### Body dan stream

Body adalah stream PSR-7. Tulis, ganti, atau baca:

```php
$res = new Response();
$res->getBody()->write('Halo');          // tulis sebelum membuat salinan
$res->getBody()->write(', dunia');
(string)$res->getBody();                 // 'Halo, dunia'

$res->withBody(Stream::from('lain'));    // salinan dengan body lain
```

Seluruh body dibaca ke memori saat response dikirim, jadi stream cocok untuk body kecil
sampai sedang. Kirim file besar dengan `Response::file()`, yang men-stream dari disk.

`Response::json()` dan kawan-kawannya menyimpan body sebagai string biasa sampai ada
yang meminta stream-nya, jadi jalur umum tidak membayar biaya stream. Tulis ke body
**sebelum** memanggil `withStatus()` atau `withHeader()`, atau tulis ke salinan yang
Anda kembalikan: stream yang dibuat belakangan tidak dibagi dengan salinan yang lebih
lama.

### Cookie

```php
$res->withCookie('session', 'abc123');
$res->withCookie('session', 'abc123', 3600, '/', '', false, true, 'Lax');
//               nama      nilai     maxAge path domain secure httpOnly sameSite
```

Setiap panggilan menambah header `Set-Cookie` sendiri, dan nilainya di-URL-encode.
Baca cookie di request berikutnya dengan `$req->cookie('session')`. Untuk menghapus,
kirim lagi dengan `maxAge` `0`.

### File

```php
Response::file('/srv/laporan.pdf', $req);      // dengan request
Response::file('/srv/laporan.pdf');            // tanpa request
```

File di-stream dari disk. Memberikan `$req` menambah dukungan `Range` (206 untuk unduhan
yang bisa dilanjutkan dan seek video), `If-Modified-Since` (304), dan `HEAD`.
`Content-Type` ditentukan dari ekstensi, dan tipe yang tidak dikenal dikirim sebagai
unduhan. Tambahkan header sendiri ke hasilnya:

```php
return Response::file($path, $req)
    ->withHeader('Content-Disposition', 'attachment; filename="laporan.pdf"');
```

File yang tidak ada menghasilkan 404. Anda juga bisa mengirim sebagian file dengan
`withFile($path, $offset, $length)`, dan `getBody()` pada response file memberi stream
berisi byte yang sama.

### Response PSR-7 apa pun

Handler boleh mengembalikan response buatan pustaka PSR-7 lain. Kuman mempertahankan
status, header, dan stream body-nya.

## File upload, stream, dan URI

Upload datang sebagai objek `UploadedFileInterface` PSR-7, bersarang seperti nama
field-nya:

```php
$app->post('/avatar', function (Request $req) {
    $file = $req->getUploadedFiles()['avatar'] ?? null;     // <input type="file" name="avatar">
    if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
        return Response::json(['error' => 'tidak ada file'], 422);
    }
    $file->moveTo('/srv/avatars/' . bin2hex(random_bytes(8)) . '.png');
    return ['size' => $file->getSize()];
});
```

| `UploadedFile` | Mengembalikan |
|---|---|
| `getClientFilename()` | Nama yang dikirim browser: **jangan percaya** sebagai path |
| `getClientMediaType()` | Tipe yang dikirim browser: juga tidak bisa dipercaya |
| `getSize()`, `getError()` | Ukuran dalam byte, dan kode `UPLOAD_ERR_*` |
| `getStream()` | Stream atas file sementara |
| `moveTo($path)` | Memindahkannya, sekali saja. Kuman menghapus file sementara yang tidak dipindah saat request selesai |

`$files['photos'][0]` adalah cara `name="photos[]"` tiba. Entri mentah dari
`$req->file('avatar')` punya kunci `name`, `type`, `tmp_name`, `size`, dan `error`.

`Kuman\Stream` adalah stream PSR-7 dengan dua constructor, `Stream::from('teks')` untuk
string dan `Stream::open($path, 'rb')` untuk file, ditambah `read()`, `write()`,
`seek()`, `getContents()`, `getSize()`, dan `close()`. `Kuman\Uri` adalah URI PSR-7:
`new Uri('https://example.com/a?b=1')`, dengan `withPath()`, `withQuery()`,
`withHost()` dan seterusnya. Keduanya immutable sesuai aturan PSR-7, dan bekerja dengan
pustaka PSR-7 lain.

## Tutorial: API catatan

Kita membangun API yang membuat catatan, melampirkan file ke catatan, dan mengunduhnya.
Isinya controller, group, middleware, cookie, dan halaman error. Buat proyek dan
`mkdir storage` di samping `app.php`.

Jalankan dengan satu worker, karena catatan disimpan di memori dan
[state tidak dibagi antar worker](../README.id.md#hal-yang-perlu-diingat):

```bash
WORKERS=1 vendor/bin/kuman
```

### 1. Sebuah sapaan

```php
<?php

use Kuman\App;
use Kuman\Request;
use Kuman\Response;

return function (App $app) {
    $app->get('/hello/{name}', function ($request, $response, array $args) {
        $response->getBody()->write('Halo, ' . htmlspecialchars($args['name']));
        return $response->withHeader('X-Greeted', 'yes');
    });
};
```

```
$ curl -i localhost:8642/hello/Ana
HTTP/1.1 200 OK
X-Greeted: yes

Halo, Ana
```

Parameter kedua membuat Kuman memberikan `Response` dan parameter route. Escape apa pun
yang Anda cetak ke HTML.

### 2. Lihat apa yang dikirim klien

```php
$app->get('/debug', fn(Request $req) => [
    'method' => $req->method(),
    'path'   => $req->path(),
    'query'  => $req->get(),
    'host'   => $req->host(true),
    'ip'     => $req->ip(),
    'agent'  => $req->header('user-agent'),
    'session' => $req->cookie('session'),
]);
```

```
$ curl -H 'User-Agent: tut' -b session=zz 'localhost:8642/debug?a=1&b[]=2'
{"method":"GET","path":"/debug","query":{"a":"1","b":["2"]},"host":"localhost","ip":"127.0.0.1","agent":"tut","session":"zz"}
```

### 3. Sebuah controller

Taruh logikanya di kelas, di `app.php` di atas closure atau di file sendiri. Kita
menyimpan catatan di properti, jadi controller dibuat **sekali** dan diberikan sebagai
instance. Nama kelas, `[NoteController::class, 'index']`, akan membuat controller baru
untuk setiap request dan melupakan catatannya.

```php
class NoteController
{
    private array $notes = [];
    private int $nextId = 1;

    public function index(Request $req): array
    {
        $q = (string)$req->get('q', '');
        return array_values(array_filter(
            $this->notes,
            fn($note) => $q === '' || stripos($note['title'], $q) !== false
        ));
    }

    public function create(Request $req): Response
    {
        $title = trim((string)$req->input('title', ''));       // JSON atau form
        if ($title === '') {
            return Response::json(['error' => 'title is required'], 422);
        }

        $note = ['id' => $this->nextId++, 'title' => $title, 'file' => null];
        $this->notes[$note['id']] = $note;
        return Response::json($note, 201)->withHeader('Location', "/api/notes/{$note['id']}");
    }

    public function show(Request $req): array|Response
    {
        return $this->notes[(int)$req->param('id')] ?? Response::json(['error' => 'not found'], 404);
    }

    public function delete(Request $req): Response
    {
        unset($this->notes[(int)$req->param('id')]);
        return new Response(204);
    }
}
```

### 4. Route dalam group, di balik kunci

```php
return function (App $app) {
    $notes = new NoteController();

    // ... route dari langkah 1 dan 2 ...

    $app->use('/api', function (Request $req, callable $next) {
        return $req->header('x-api-key') === 'secret'
            ? $next($req)
            : Response::json(['error' => 'missing or wrong API key'], 401);
    });

    $app->group('/api/notes', function (App $api) use ($notes) {
        $api->get('/', [$notes, 'index']);
        $api->post('/', [$notes, 'create']);
        $api->get('/:id', [$notes, 'show']);
        $api->delete('/:id', [$notes, 'delete']);
    });
};
```

```
$ curl -i localhost:8642/api/notes
HTTP/1.1 401 Unauthorized
{"error":"missing or wrong API key"}

$ curl -i -H 'X-Api-Key: secret' -H 'Content-Type: application/json' \
       -d '{"title":"Buy milk"}' localhost:8642/api/notes
HTTP/1.1 201 Created
Location: /api/notes/1
{"id":1,"title":"Buy milk","file":null}

$ curl -H 'X-Api-Key: secret' -d 'title=Call mom' localhost:8642/api/notes    # form juga bisa
{"id":2,"title":"Call mom","file":null}

$ curl -H 'X-Api-Key: secret' 'localhost:8642/api/notes?q=milk'
[{"id":1,"title":"Buy milk","file":null}]

$ curl -i -H 'X-Api-Key: secret' -d 'title=' localhost:8642/api/notes
HTTP/1.1 422 Unprocessable Entity
{"error":"title is required"}

$ curl -i -H 'X-Api-Key: secret' -X DELETE localhost:8642/api/notes/2
HTTP/1.1 204 No Content
```

### 5. Lampirkan dan unduh file

Tambahkan dua method ke `NoteController`, dan dua route ke group.

```php
public function attach(Request $req, Response $res, array $args): Response
{
    $id = (int)$args['id'];
    $file = $req->getUploadedFiles()['file'] ?? null;
    if (!isset($this->notes[$id])) {
        return Response::json(['error' => 'not found'], 404);
    }
    if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
        return Response::json(['error' => 'file is required'], 422);
    }

    // Jangan percaya nama file dari klien: sisakan huruf, angka, titik, minus, dan underscore.
    $name = $id . '-' . preg_replace('/[^\w.-]/', '_', basename($file->getClientFilename()));
    $file->moveTo(__DIR__ . "/storage/$name");
    $this->notes[$id]['file'] = $name;

    $res->getBody()->write(json_encode($this->notes[$id]));
    return $res->withStatus(201)->withHeader('Content-Type', 'application/json');
}

public function download(Request $req): Response
{
    $file = $this->notes[(int)$req->param('id')]['file'] ?? null;
    if ($file === null) {
        return Response::json(['error' => 'no file attached'], 404);
    }
    return Response::file(__DIR__ . "/storage/$file", $req)
        ->withHeader('Content-Disposition', "attachment; filename=\"$file\"");
}
```

```php
$api->post('/:id/file', [$notes, 'attach']);
$api->get('/:id/file', [$notes, 'download']);
```

```
$ curl -i -H 'X-Api-Key: secret' -F 'file=@up.txt;filename=my notes!.txt' localhost:8642/api/notes/1/file
HTTP/1.1 201 Created
Content-Type: application/json
{"id":1,"title":"Buy milk","file":"1-my_notes_.txt"}

$ curl -i -H 'X-Api-Key: secret' localhost:8642/api/notes/1/file
HTTP/1.1 200 OK
Content-Disposition: attachment; filename="1-my_notes_.txt"
Content-Type: text/plain
hello file

$ curl -i -H 'X-Api-Key: secret' -H 'Range: bytes=0-4' localhost:8642/api/notes/1/file
HTTP/1.1 206 Partial Content
hello
```

`attach` memakai tanda tangan gaya Slim karena nyaman punya `$args` dan response kosong.
`download` mengembalikan `Response::file()`, yang mendukung `Range` tanpa kerja tambahan.

### 6. Cookie dan redirect

```php
$app->get('/login', fn() => Response::redirect('/welcome')
    ->withCookie('session', 'abc123', 3600, '/', '', false, true, 'Lax'));

$app->get('/welcome', fn(Request $req) => 'Session: ' . $req->cookie('session', 'none'));
```

```
$ curl -i localhost:8642/login
HTTP/1.1 302 Found
Location: /welcome
Set-Cookie: session=abc123; Max-Age=3600; Path=/; HttpOnly; SameSite=Lax

$ curl -b session=abc123 localhost:8642/welcome
Session: abc123
```

### 7. Request id di setiap response

Middleware global yang menyimpan nilai untuk kode berikutnya, dan menghias response:

```php
$app->use(function (Request $req, callable $next) {
    $id = bin2hex(random_bytes(4));
    return $next($req->withAttribute('request-id', $id))?->withHeader('X-Request-Id', $id);
});

// di handler mana pun: $req->getAttribute('request-id')
```

`$next()` mengembalikan `null` ketika handler memegang koneksi, karena itu `?->`.

### 8. Halaman error

```php
$app->notFound(fn(Request $req) => Response::json(['error' => 'no route', 'path' => $req->path()], 404));
$app->onError(fn(Throwable $e) => Response::json(['error' => 'server error'], 500));
```

```
$ curl localhost:8642/nothing
{"error":"no route","path":"/nothing"}
```

Exception dicatat ke `runtime/kuman.log` sebelum handler berjalan, dan worker tetap hidup.

## Hal-hal yang sering menjebak

- **Pakai hasil `with*()`.** `$res->withHeader(...)` di baris sendiri tidak melakukan apa-apa.
- **Kembalikan response-nya.** Handler tanpa `return` mengembalikan `null`, dan `null`
  berarti "saya jawab nanti", jadi klien menunggu. Ini berlaku juga untuk handler gaya
  Slim.
- **Tulis ke body response lebih dulu.** Stream yang dibuat setelah `withStatus()` atau
  `withHeader()` tidak dibagi dengan salinan yang lebih lama.
- **Teruskan salinannya ke `$next`.** `$next($req->withAttribute(...))`, bukan `$next($req)`.
- **`post()` membaca setiap body yang bukan JSON sebagai form.** Untuk XML, teks biasa,
  atau data biner hasilnya tidak berarti, jadi pakai `rawBody()` atau `getBody()`.
  `getParsedBody()` bernilai `null` untuk tipe itu.
- **Jangan percaya upload.** Nama dan tipe file datang dari klien. Pilih sendiri nama
  untuk menyimpannya, dan periksa tipe sebenarnya dengan `finfo` kalau penting.
- **Nama header dari klien berhuruf kecil.** `header('X-Token')` dan `header('x-token')`
  sama-sama bekerja; `getHeaders()` memakai kunci huruf kecil.
- **Nama kelas controller membuat controller baru per request.** Berikan instance
  (`[$controller, 'method']`) kalau ia harus menyimpan state.

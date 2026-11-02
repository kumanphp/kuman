# Injeksi

Handler mendeklarasikan apa yang dibutuhkannya lewat parameter bertipe, dan Kuman
mengirimkannya. Kuman menentukan asal setiap parameter sekali saja, saat worker start.
Parameter yang tidak bisa dipenuhi menghentikan worker di situ dengan namanya di pesan
error, bukan menggagalkan request belakangan.

- [Apa yang bisa diminta handler](#apa-yang-bisa-diminta-handler)
- [Handler yang sudah ada tetap berjalan](#handler-yang-sudah-ada-tetap-berjalan)
- [Layanan untuk seluruh worker](#layanan-untuk-seluruh-worker)
- [Nilai yang dibuat per request](#nilai-yang-dibuat-per-request)
- [Depends](#depends)
- [Controller](#controller)
- [Masa hidup](#masa-hidup)
- [Hal-hal yang sering menjebak](#hal-hal-yang-sering-menjebak)

## Apa yang bisa diminta handler

| Parameter | Menerima |
|---|---|
| `Request $req`, atau `ServerRequestInterface` | request, berisi user yang diloloskan guard |
| `Response $res`, atau `ResponseInterface` | respons kosong untuk diisi |
| `array $args` | parameter route, seperti di Slim |
| Kelas `Dto` | input yang sudah tervalidasi, lihat [Validasi](validation.md) |
| `int $id` yang namanya sama dengan parameter route | parameter itu, sudah dikonversi |
| `#[Query]`, `#[Header]`, `#[Cookie]`, `#[Body]` | satu nilai dari bagian request itu |
| Kelas yang didaftarkan ke `provide()` | objek itu |
| Kelas yang didaftarkan ke `scoped()` | objek yang dibuat untuk request ini |
| `#[Depends(...)]` | hasil fungsi lain |
| Apa pun yang punya default | default-nya, kalau tidak ada yang di atas berlaku |

```php
$app->post('/teams/:team/members', function (
    Request $req,
    int $team,
    AddMember $input,
    Mailer $mailer,
) {
    $mailer->invite($input->email, $team, $req->user());
    return Response::json(['invited' => $input->email], 201);
});
```

## Handler yang sudah ada tetap berjalan

Handler yang ditulis dengan bentuk biasa tetap memakai bentuk itu. Kuman hanya melakukan
injeksi kalau ada parameter yang meminta sesuatu di luar bentuk tersebut: Dto, parameter
route bertipe, attribute, atau layanan.

```php
fn() => ...;                                   // seperti dulu
fn(Request $req) => ...;                       // seperti dulu
fn($request, $response, array $args) => ...;   // seperti dulu, gaya Slim
fn($req, $res, $id) => ...;                    // seperti dulu: tanpa tipe berarti posisi
```

Parameter tanpa tipe tidak pernah dibaca berdasarkan nama. Di handler yang di-inject,
parameter tanpa tipe menghentikan worker saat start, karena Kuman tidak bisa tahu isinya.

## Layanan untuk seluruh worker

`provide()` mendaftarkan objek yang diminta handler berdasarkan kelasnya. Buat objeknya di
closure `app.php`, yang berjalan di setiap worker setelah fork:

```php
return function (App $app) {
    $app->provide(PDO::class, new PDO($dsn, $user, $password));
    $app->provide(Mailer::class, new Mailer(getenv('SMTP_URL')));

    $app->get('/users/:id', fn(int $id, PDO $db) => findUser($db, $id));
};
```

Satu objek melayani semua request di worker itu, jadi objek tersebut tidak boleh menyimpan
apa pun tentang sebuah request. Koneksi database aman; properti "user saat ini" tidak.
`provide()` boleh dipanggil setelah route yang memakainya.

## Nilai yang dibuat per request

`scoped()` mendaftarkan fungsi yang membuat objek ketika sebuah request memintanya.
Parameter fungsi itu sendiri di-inject dengan cara yang sama. Fungsi ini paling banyak
berjalan sekali per request, berapa pun parameter dan dependency yang meminta kelasnya:

```php
$app->scoped(Tenant::class, function (#[Header('x-tenant')] string $slug, PDO $db) {
    return findTenant($db, $slug) ?? throw new Abort(Response::json(['error' => 'unknown tenant'], 404));
});

$app->get('/orders', fn(Tenant $tenant, PDO $db) => ordersOf($db, $tenant));
```

Ketika request dijawab, objeknya ikut dibuang. Tidak ada yang perlu di-reset.

## Depends

`#[Depends]` menyebut fungsinya di parameter, bukan di kelas, untuk nilai yang tipenya
kurang bermakna seperti string atau int. Attribute tidak bisa menampung closure, jadi
`#[Depends]` menerima kelas invokable atau `[SomeClass::class, 'method']`:

```php
use Kuman\Attributes\Depends;

final class CurrentLocale
{
    public function __invoke(#[Header('accept-language')] string $language = 'en'): string
    {
        return substr($language, 0, 2);
    }
}

$app->get('/greeting', fn(#[Depends(CurrentLocale::class)] string $locale) => greet($locale));
```

Dependency bisa menghentikan request dengan melempar `Kuman\Abort`, lihat
[Validasi](validation.md#menghentikan-request-sendiri). Kalau dependency butuh nilai yang
tidak ada, error-nya dilaporkan bersama error handler, dan dependency itu tidak dipanggil.

Dependency yang bergantung pada dirinya sendiri, lewat dependency lain, menghentikan
worker saat start.

## Controller

Controller yang disebut lewat nama kelas juga mendapat injeksi untuk parameter
konstruktornya, dan parameter method-nya diperlakukan seperti handler lain:

```php
class OrderController
{
    public function __construct(private PDO $db)
    {
    }

    public function show(int $id): array
    {
        return findOrder($this->db, $id);
    }
}

$app->provide(PDO::class, $db);
$app->get('/orders/:id', [OrderController::class, 'show']);
```

Controller tetap dibuat untuk setiap request. Controller yang dikirim sebagai instance,
`[$controller, 'show']`, dipakai apa adanya. Middleware route yang ditulis sebagai method
controller tidak mendapat injeksi: tetap `($request, $next)` dengan konstruktor tanpa
argumen.

## Masa hidup

| Didaftarkan dengan | Dibuat | Hidup selama |
|---|---|---|
| `provide()` | sekali, oleh Anda, di `app.php` | umur worker |
| `scoped()`, `#[Depends]` | saat request pertama kali memintanya | request itu |
| Dto, parameter | untuk setiap request | request itu |

Di fiber loop, request yang sedang suspend di worker yang sama masing-masing memegang
nilainya sendiri.

## Hal-hal yang sering menjebak

- **Guard adalah middleware, bukan dependency.** Pakai guard untuk menolak request, dan baca
  user dengan `$req->user()`. Dependency berjalan setelah semua middleware.
- **Pemanggilan blocking tetap blocking.** `PDO` dari `provide()` melayani satu query sekali
  waktu per worker, seperti sebelumnya.
- **Fungsi `scoped()` yang tidak pernah diminta tidak pernah berjalan.** Jangan andalkan
  untuk efek samping seperti logging.
- **Route statis tetap harus didahulukan.** Injeksi tidak mengubah aturan routing.

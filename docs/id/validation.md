# Validasi

Handler bisa meminta sebuah **Dto**, yaitu kelas yang properti public-nya menggambarkan
input. Kuman mengisinya dari request dan memeriksanya sebelum handler berjalan. Kalau
inputnya salah, handler tidak dijalankan dan klien menerima 422 yang mendaftar semua
masalahnya.

- [Mulai cepat](#mulai-cepat)
- [Field dan tipe](#field-dan-tipe)
- [Wajib dan opsional](#wajib-dan-opsional)
- [Rule](#rule)
- [Dari mana datanya](#dari-mana-datanya)
- [Objek bersarang dan list](#objek-bersarang-dan-list)
- [Upload](#upload)
- [Nilai tunggal](#nilai-tunggal)
- [Respons error](#respons-error)
- [Menghentikan request sendiri](#menghentikan-request-sendiri)
- [Hal-hal yang sering menjebak](#hal-hal-yang-sering-menjebak)

## Mulai cepat

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

```
POST /users  {"email": "nope"}

422 {"error": "Unprocessable Entity", "details": [
  {"field": "email", "rule": "email", "message": "Must be a valid email address."},
  {"field": "password", "rule": "required", "message": "This field is required."}
]}
```

Handler boleh mencampur Dto dengan apa pun yang bisa dimintanya, dalam urutan apa pun:
`fn(Request $req, CreateUser $user, int $id)`. Lihat [Injeksi](injection.md).

## Field dan tipe

Setiap properti public yang tidak static adalah field. Properti private dan static, serta
key di request yang bukan field, diabaikan, sehingga klien tidak bisa mengisi sesuatu yang
tidak Anda deklarasikan. Konstruktor tidak dijalankan; Kuman membuat objeknya lalu
mengisi propertinya.

| Tipe | Body JSON | Form, query string, header, path |
|---|---|---|
| `string` | string | apa adanya |
| `int` | integer | `"42"` menjadi `42`, `"4.2"` dan `"abc"` ditolak |
| `float` | angka; integer diterima | string numerik |
| `bool` | `true` atau `false` | `1`, `0`, `true`, `false`, `on`, `off`, `yes`, `no`; kosong ditolak |
| `array` | array atau objek | `items[]=a&items[]=b` |
| Backed enum | nilainya, dengan tipe backing-nya | nilainya; `"2"` untuk enum int |
| `Dto` lain | objek | `address[city]=...` |
| `UploadedFileInterface` | | file dari body multipart |
| `mixed` atau tanpa tipe | apa saja | apa saja |

JSON diperiksa ketat karena JSON membawa tipe: `"30"` adalah string dan tidak mengisi
`int`. Form, query string, header, dan path hanya membawa teks, jadi nilainya dikonversi.
Nilai bertipe salah ditolak dengan rule `type` dan tidak pernah sampai ke properti,
sehingga klien tidak bisa memicu `TypeError`.

Union type, `object`, `DateTimeImmutable`, dan kelas lain tidak didukung. Dto yang
memakainya menghentikan worker saat start dengan pesan yang menyebut propertinya, sama
seperti kesalahan routing.

## Wajib dan opsional

Field **wajib** kalau tipenya tidak mengizinkan `null` dan tidak punya default:

```php
public string $email;            // wajib
public string $role = 'user';    // opsional, 'user' kalau tidak dikirim
public ?string $nick = null;     // opsional, null kalau tidak dikirim
public ?string $bio;             // juga opsional: nullable, jadi null kalau tidak dikirim
public readonly ?string $note;   // sama; PHP melarang default pada properti readonly
```

`null` yang dikirim untuk field yang tidak mengizinkannya dianggap tidak dikirim, sehingga
default dipakai atau field dilaporkan hilang.

`#[Required]` membuat field wajib walaupun punya default, juga menolak `''` dan `[]`, dan
menentukan pesannya:

```php
#[Required('Isi nama Anda.')]
public string $name = '';
```

## Rule

Rule adalah attribute di `Kuman\Validation\Attributes`. Rule hanya berjalan pada nilai
yang lolos pemeriksaan tipe, dan rule pertama yang gagal di sebuah field-lah yang
dilaporkan.

| Rule | Lolos kalau |
|---|---|
| `#[Required]` | ada, bukan `null`, `''`, atau `[]` |
| `#[Email]` | alamat email yang valid |
| `#[MinLength(8)]`, `#[MaxLength(64)]` | jumlah karakter string, jumlah item array |
| `#[Min(1)]`, `#[Max(100)]` | angka dalam rentang |
| `#[Numeric]` | string berisi angka, misalnya harga yang disimpan sebagai teks |
| `#[Pattern('/^[a-z]+$/')]` | regular expression cocok; pola yang rusak menghentikan worker saat start |

Setiap rule menerima pesan sebagai argumen terakhir: `#[MinLength(8, 'Terlalu pendek.')]`.

Rule buatan sendiri meng-extend `Kuman\Validation\Rule`:

```php
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Slug extends Rule
{
    public function passes(mixed $value): bool
    {
        return preg_match('/^[a-z0-9-]+$/', $value) === 1;
    }

    protected function fallback(): string
    {
        return 'Harus berupa slug.';
    }
}
```

Nama rule di error adalah nama kelasnya dengan huruf pertama kecil, `slug`. Satu objek rule
melayani semua request di satu worker, jadi jangan simpan state di dalamnya.

## Dari mana datanya

| Request | Dto diisi dari |
|---|---|
| `GET`, `HEAD` | query string |
| Method lain | body: JSON, form, atau multipart |
| Dengan `#[Query]`, `#[Body]`, `#[Header]`, atau `#[Cookie]` pada parameter | bagian itu |

```php
use Kuman\Attributes\Query;

$app->delete('/posts', fn(#[Query] PostFilter $filter) => ...);
```

Body tidak pernah dicampur dengan query string. `$req->all()` menggabungkan keduanya,
sehingga `POST /users?role=admin` akan mengisi field `role` yang tidak ada di form.

Body JSON yang rusak dijawab 400. Body JSON berupa list atau satu nilai, bukan objek,
dijawab 422.

## Objek bersarang dan list

Properti bertipe Dto lain menerima objek. PHP tidak punya array bertipe, jadi `#[ArrayOf]`
menyebut tipe item-nya: nama tipe skalar, Dto, atau enum.

```php
class Order implements Dto
{
    public Address $address;

    #[ArrayOf(Item::class)]
    #[MinLength(1)]
    public array $items;

    #[ArrayOf('int')]
    public array $codes = [];
}
```

Error membawa path-nya: `address.city`, `items.1.qty`.

## Upload

```php
class Avatar implements Dto
{
    public UploadedFileInterface $file;
    public string $caption = '';
}

$app->post('/avatar', function (Avatar $input) {
    $input->file->moveTo('/srv/avatars/' . bin2hex(random_bytes(8)));
    return ['ok' => true];
});
```

File hanya diperiksa apakah upload-nya berhasil, bukan ukuran atau jenisnya. Periksa
`getSize()` dan `getClientMediaType()` sendiri, dan jangan pernah memakai nama file dari
klien sebagai path.

## Nilai tunggal

Parameter handler juga bisa dibaca dengan cara yang sama, dengan tipe, rule, dan default
yang sama:

```php
use Kuman\Attributes\{Cookie, Header, Query};
use Kuman\Validation\Attributes\Min;

$app->get('/users/:id', fn(
    int $id,                                    // parameter route dengan nama yang sama
    #[Query] #[Min(1)] int $page = 1,           // ?page=2
    #[Query('per_page')] int $perPage = 20,     // ?per_page=50
    #[Header] string $xTenant = 'main',         // X-Tenant
    #[Cookie('sid')] ?string $session = null,
) => ...);
```

Parameter route harus bertipe agar dibaca dengan cara ini. `/users/abc` untuk `int $id`
menghasilkan 422, bukan 404; beri batasan di path, `/users/:id(\d+)`, untuk mendapat 404.

## Respons error

```json
{"error": "Unprocessable Entity", "details": [{"field": "...", "rule": "...", "message": "..."}]}
```

`rule` stabil, jadi klien bisa bertindak berdasarkan nilainya. `type` dan `required`
adalah rule untuk tipe yang salah dan nilai yang hilang. Pesannya berbahasa Inggris;
terjemahkan, atau ubah seluruh jawabannya, dengan `onInvalid()`:

```php
use Kuman\Validation\Invalid;

$app->onInvalid(function (Invalid $e, Request $req) {
    $messages = ['required' => 'Wajib diisi.', 'type' => 'Tipe data salah.'];
    return Response::json(['errors' => array_map(
        fn(array $error) => ['field' => $error['field'], 'message' => $messages[$error['rule']] ?? $error['message']],
        $e->details
    )], 422);
});
```

Jawabannya dikembalikan lewat middleware, sama seperti jawaban handler: header CORS dan
logger tetap melihatnya.

## Menghentikan request sendiri

Pemeriksaan yang butuh database, misalnya email yang sudah terdaftar, tempatnya di handler.
Lempar `Invalid` untuk menjawab dengan format yang sama:

```php
throw new Invalid([['field' => 'email', 'rule' => 'taken', 'message' => 'Sudah terdaftar.']]);
```

`Kuman\Abort` menjawab dengan respons apa pun, dan tidak dicatat sebagai error:

```php
throw new Abort(Response::json(['error' => 'forbidden'], 403));
```

Kalau dilempar dari handler yang meminta sesuatu untuk di-inject, atau dari dependency,
jawabannya melewati middleware. Dari tempat lain, request tetap dijawab, tetapi middleware
di antaranya tidak melihatnya.

## Hal-hal yang sering menjebak

- **Konstruktor tidak dijalankan.** Validasi lewat rule, atau sesudahnya di handler.
- **`"30"` di JSON bukan int.** Kirim angka sebagai angka, atau beri field tipe `string`
  lalu tambahkan `#[Numeric]`.
- **Guard berjalan lebih dulu.** Route dengan guard menjawab 401 sebelum validasi melihat
  body, sehingga klien anonim tidak tahu apa-apa tentang field-nya.
- **Field yang tidak dikenal dibuang diam-diam.** Ini melindungi dari mass assignment,
  tetapi juga menyembunyikan salah ketik nama field di sisi klien.
- **Hanya handler yang menjawab yang di-inject.** Middleware route tetap `($request, $next)`.

<?php

namespace Fixture;

use Kuman\Abort;
use Kuman\Attributes\Depends;
use Kuman\Attributes\Description;
use Kuman\Attributes\Header;
use Kuman\Attributes\Hidden;
use Kuman\Attributes\Returns;
use Kuman\Attributes\Summary;
use Kuman\Attributes\Tag;
use Kuman\Request;
use Kuman\Response;
use Kuman\Validation\Attributes\ArrayOf;
use Kuman\Validation\Attributes\Email;
use Kuman\Validation\Attributes\Max;
use Kuman\Validation\Attributes\MaxLength;
use Kuman\Validation\Attributes\Min;
use Kuman\Validation\Attributes\MinLength;
use Kuman\Validation\Attributes\Numeric;
use Kuman\Validation\Attributes\Pattern;
use Kuman\Validation\Attributes\Required;
use Kuman\Validation\Dto;
use Psr\Http\Message\UploadedFileInterface;
use Workerman\Timer;

enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
}

enum Level: int
{
    case Low = 1;
    case High = 2;
}

class CreateUser implements Dto
{
    #[Email]
    #[Description('Where the receipt goes.')]
    public string $email;

    #[MinLength(8)]
    #[MaxLength(12)]
    public string $password;

    #[Min(13)]
    #[Max(120)]
    public int $age = 18;

    public ?string $nick = null;
    public bool $active = false;
    public float $score = 0.0;
    public Plan $plan = Plan::Free;
    public ?Level $level = null;

    // Cannot have a default in PHP, so a nullable field without one is optional.
    public readonly ?string $note;

    public static int $ignored = 0;
    private string $internal = 'kept';
}

class Address implements Dto
{
    #[MinLength(2)]
    public string $city;
}

class Item implements Dto
{
    public string $sku;

    #[Min(1)]
    public int $qty = 1;
}

class Order implements Dto
{
    public Address $address;

    #[ArrayOf(Item::class)]
    #[MinLength(1)]
    public array $items;

    #[ArrayOf('int')]
    public array $codes = [];

    #[ArrayOf(Plan::class)]
    public array $plans = [];

    public ?Order $parent = null;
}

class Search implements Dto
{
    #[Pattern('/^[a-z]+$/')]
    public string $q;

    public int $page = 1;
    public bool $exact = false;
}

class Promoted implements Dto
{
    public function __construct(
        #[Email] public readonly string $email,
        public readonly string $role = 'guest',
    ) {
        throw new \LogicException('A Dto constructor does not run.');
    }
}

class Messaged implements Dto
{
    #[Required('Tell us your name.')]
    public string $name = 'nobody';

    #[Numeric('Digits, please.')]
    public string $price = '0';

    #[Pattern('/^x/', 'Starts with x.')]
    public ?string $code = null;
}

class Attachment implements Dto
{
    public UploadedFileInterface $doc;
    public string $title;
}

class UserOut
{
    public int $id;
    public string $email;
    public ?Address $address = null;
    public \DateTimeImmutable|string $at = '';
}

class BadProperty implements Dto
{
    public \DateTimeImmutable $at;
}

class UnionProperty implements Dto
{
    public int|string $id;
}

abstract class AbstractDto implements Dto
{
}

class BadItems implements Dto
{
    #[ArrayOf('Fixture\\Nothing')]
    public array $items;
}

class Clock
{
    public int $calls = 0;
}

class Tenant
{
    public function __construct(public string $name, public int $call)
    {
    }
}

final class TenantName
{
    public function __invoke(Tenant $tenant): string
    {
        return strtoupper($tenant->name);
    }
}

final class Deps
{
    public static function deny(): string
    {
        throw new Abort(Response::json(['error' => 'forbidden'], 403));
    }

    public static function needsHeader(#[Header('x-need')] int $need): int
    {
        return $need;
    }

    public static function slowName(#[Header('x-round')] string $round): string
    {
        Timer::sleep(0.1);
        return $round;
    }
}

class InjectedController
{
    public function __construct(private Clock $clock, private Request $request, private string $greeting = 'hi')
    {
    }

    #[Summary('Show one')]
    #[Description('Reads the path and the clock.')]
    #[Returns(200, UserOut::class)]
    #[Returns(404, description: 'No such user')]
    public function show(int $id): array
    {
        return ['id' => $id, 'greeting' => $this->greeting, 'path' => $this->request->path()];
    }

    public function plain(Request $req): array
    {
        return ['path' => $req->path(), 'clock' => $this->clock instanceof Clock];
    }

    public function slim($request, $response, array $args): Response
    {
        return $response->withHeader('X-Id', $args['id']);
    }
}

#[Tag('users')]
class DocumentedController
{
    #[Tag('admin')]
    public function list(): UserOut
    {
        return new UserOut();
    }

    #[Returns(200, [UserOut::class])]
    #[Returns(201, 'string')]
    public function many(): array
    {
        return [];
    }

    #[Hidden]
    public function secret(): array
    {
        return [];
    }
}

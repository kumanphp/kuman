<?php

namespace Fixture;

use Kuman\Request;
use Kuman\Response;

class UserController
{
    private int $calls = 0;

    public function show(Request $req): array
    {
        return ['id' => $req->param('id'), 'calls' => ++$this->calls];
    }

    public function create(Request $req): Response
    {
        return Response::json(['created' => $req->input('name')], 201);
    }

    public function mine(): string
    {
        return 'mine';
    }

    public function profile(Request $req, Response $res, array $args): Response
    {
        $res->getBody()->write('profile ' . $args['id']);
        return $res->withStatus(202);
    }

    // A controller method can be route middleware too.
    public function gate(Request $req, callable $next)
    {
        return $req->header('x-pass') === 'yes' ? $next($req) : Response::text('gate closed', 403);
    }

    private function hidden(): string
    {
        return 'hidden';
    }
}

class HealthController
{
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}

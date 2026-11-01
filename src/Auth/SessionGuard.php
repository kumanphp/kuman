<?php

declare(strict_types=1);

namespace Kuman\Auth;

use Kuman\Request;
use Kuman\Response;
use Workerman\Protocols\Http\Session;

/**
 * A login kept in the session, started by Auth::login() and ended by Auth::logout().
 */
class SessionGuard extends Guard
{
    public const KEY = 'auth';

    /** @var ?callable */
    private $resolve;

    /**
     * @param ?callable $resolve fn(int|string $id) returning the user, null or false to refuse.
     * @param ?string $redirect Send refused requests here instead of answering 401.
     */
    public function __construct(?callable $resolve = null, private readonly ?string $redirect = null)
    {
        $this->resolve = $resolve;
    }

    public function authenticate(Request $request): mixed
    {
        // session() would start a session and send a cookie to every anonymous visitor.
        if (!$request->isValidSessionId($request->cookie(Session::$name))) {
            return null;
        }

        $id = $request->session()->get(self::KEY);
        return $id === null || $this->resolve === null ? $id : ($this->resolve)($id);
    }

    protected function challenge(Request $request): Response
    {
        return $this->redirect === null ? parent::challenge($request) : Response::redirect($this->redirect);
    }
}

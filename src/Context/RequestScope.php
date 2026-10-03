<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Context;

use Mbolli\PhpVia\Context;
use OpenSwoole\Coroutine;

/**
 * The request an action runs for: its input, uploaded files, cookies and middleware attributes, and the cookies and
 * session rotation it queues for its response. It is bound to the coroutine that handles the request, so two actions
 * of one tab that run at once each read their own request and answer with their own cookies.
 *
 * @internal
 *
 * @phpstan-type QueuedCookie array{name: string, value: string, expires: int, path: string, domain: string, secure: bool, httpOnly: bool, sameSite: string}
 * @phpstan-type UploadedFile array{name: string, type: string, tmp_name: string, error: int, size: int}
 */
final class RequestScope {
    private const string KEY = 'via.request';

    /** Whether regenerateSession() asked for a new session cookie with the response */
    public private(set) bool $rotatesSession = false;

    /** @var array<int, self> scopes bound outside a coroutine, as TestApp handles requests, by Fiber (0 for none) */
    private static array $outside = [];

    /** @var list<QueuedCookie> */
    private array $queuedCookies = [];

    private bool $answered = false;

    /**
     * @param Context                     $page       the page context of the tab, also for a component's action
     * @param array<string, mixed>        $input      the merged query and POST parameters
     * @param array<string, UploadedFile> $files
     * @param array<string, string>       $cookies
     * @param array<string, mixed>        $attributes set by the middleware that ran on the request
     */
    public function __construct(
        public readonly Context $page,
        public readonly array $input,
        private readonly array $files,
        public readonly array $cookies,
        public readonly array $attributes,
    ) {}

    /**
     * The request of $context's tab that the running code serves: the one bound to this coroutine, or to a coroutine
     * this one was started from while that one runs. Null outside an action.
     */
    public static function current(Context $context): ?self {
        $page = $context->getComponentManager()->getParentPageContext() ?? $context;
        $cid = Coroutine::getCid();
        if ($cid < 0) {
            $scope = self::$outside[self::fiberKey()] ?? null;

            return $scope?->page === $page ? $scope : null;
        }

        while ($cid > 0) {
            // getContext() is null for a coroutine that has ended.
            $scope = Coroutine::getContext($cid)[self::KEY] ?? null;
            if ($scope instanceof self && $scope->page === $page) {
                return $scope;
            }
            $cid = Coroutine::getPcid($cid);
            // @phpstan-ignore identical.alwaysFalse (the extension declares int, but an ended coroutine gives false)
            if ($cid === false) {
                return null;
            }
        }

        return null;
    }

    public function bind(): void {
        if (Coroutine::getCid() < 0) {
            self::$outside[self::fiberKey()] = $this;

            return;
        }

        $context = Coroutine::getContext();
        if ($context !== null) {
            $context[self::KEY] = $this;
        }
    }

    public function unbind(): void {
        if (Coroutine::getCid() < 0) {
            $key = self::fiberKey();
            if ((self::$outside[$key] ?? null) === $this) {
                unset(self::$outside[$key]);
            }

            return;
        }

        $context = Coroutine::getContext();
        if ($context !== null && ($context[self::KEY] ?? null) === $this) {
            unset($context[self::KEY]);
        }
    }

    /**
     * An uploaded file, null once the request is answered: OpenSwoole deletes the temporary file with the request.
     *
     * @return null|UploadedFile
     */
    public function file(string $name): ?array {
        return $this->answered ? null : $this->files[$name] ?? null;
    }

    /**
     * Queue a cookie for the response, unless it is already sent.
     *
     * @param QueuedCookie $cookie
     */
    public function queueCookie(array $cookie): bool {
        if ($this->answered) {
            return false;
        }
        $this->queuedCookies[] = $cookie;

        return true;
    }

    /**
     * Ask for a new session cookie with the response, unless it is already sent.
     */
    public function rotateSession(): bool {
        if ($this->answered) {
            return false;
        }
        $this->rotatesSession = true;

        return true;
    }

    /**
     * Mark the request answered and take the cookies queued for its response.
     *
     * @return list<QueuedCookie>
     */
    public function answer(): array {
        $this->answered = true;
        $cookies = $this->queuedCookies;
        $this->queuedCookies = [];

        return $cookies;
    }

    private static function fiberKey(): int {
        $fiber = \Fiber::getCurrent();

        return $fiber !== null ? spl_object_id($fiber) : 0;
    }
}

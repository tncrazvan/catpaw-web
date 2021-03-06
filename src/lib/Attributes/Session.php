<?php

namespace CatPaw\Web\Attributes;

use Amp\Http\Cookie\ResponseCookie;
use Amp\LazyPromise;
use Amp\Promise;
use Attribute;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Web\HttpContext;
use CatPaw\Web\Session\SessionOperationsInterface;
use ReflectionParameter;

/**
 * Attach this to a parameter.
 *
 * Catpaw will provide and start (if not already
 * started) the session of the current user.
 *
 * This parameter <b>MUST</b> be of type "array" and it must be a pointer.
 */
#[Attribute]
class Session implements AttributeInterface {
    use CoreAttributeDefinition;

    private string $id;
    private array  $STORAGE = [];
    private int    $time;

    private static false|SessionOperationsInterface $operations = false;

    public static function setOperations(SessionOperationsInterface $operations): void {
        self::$operations = $operations;
    }

    public static function getOperations(): false|SessionOperationsInterface {
        return self::$operations;
    }

    public static function create(): Session {
        return new Session();
    }

    public function setId(string $id): void {
        $this->id = $id;
    }

    public function getTime(): int {
        return $this->time;
    }

    public function setTime(int $time): void {
        $this->time = $time;
    }

    public function getId(): string {
        return $this->id;
    }

    public function &storage(): array {
        return $this->STORAGE;
    }

    public function setStorage(array &$storage): void {
        $this->STORAGE = $storage;
    }

    public function &get(string $key) {
        return $this->STORAGE[$key];
    }

    public function set(string $key, $object): void {
        $this->STORAGE[$key] = $object;
    }

    public function remove(string $key): void {
        unset($this->STORAGE[$key]);
    }

    public function has(string $key): bool {
        return isset($this->STORAGE[$key]);
    }


    public function onParameter(ReflectionParameter $reflection, mixed &$value, mixed $context): Promise {
        /** @var false|HttpContext $http */
        return new LazyPromise(function() use (
            $reflection,
            &$value,
            $context
        ) {
            if (!$context) {
                return;
            }
            /** @var Session $session */
            $sessionIDCookie = $context->request->getCookie("session-id") ?? false;
            $sessionID       = $sessionIDCookie ? $sessionIDCookie->getValue() : '';
            $session         = yield $context->sessionOperations->validateSession(id: $sessionID);
            if (!$session) {
                $session = yield $context->sessionOperations->startSession($sessionID);
            }
            if ($session->getId() !== $sessionID) {
                $context->response->setCookie(new ResponseCookie("session-id", $session->getId()));
            }

            $value = $session;
        });
    }
}

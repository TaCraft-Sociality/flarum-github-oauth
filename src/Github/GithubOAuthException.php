<?php

declare(strict_types=1);

namespace TacraftSociality\GithubOAuth\Github;

use RuntimeException;
use Throwable;

class GithubOAuthException extends RuntimeException
{
    public function __construct(
        private readonly string $reason,
        string $message = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}

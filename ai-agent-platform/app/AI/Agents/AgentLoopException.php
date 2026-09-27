<?php

namespace App\AI\Agents;

use RuntimeException;
use Throwable;

final class AgentLoopException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $usage
     * @param  list<array<string, mixed>>  $toolLog
     */
    public function __construct(Throwable $previous, public readonly array $usage, public readonly array $toolLog)
    {
        parent::__construct($previous->getMessage(), (int) $previous->getCode(), $previous);
    }
}

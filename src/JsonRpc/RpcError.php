<?php

declare(strict_types=1);

namespace Prism\Acp\JsonRpc;

use Prism\Acp\Support\Undefined;

/** An error a handler throws to control the code the peer sends. */
final class RpcError extends \RuntimeException
{
    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INVALID_PARAMS = -32602;

    public const INTERNAL_ERROR = -32603;

    /** @param mixed $data the error's `data`, or Undefined for none (the field is then left out, not sent as null) */
    public function __construct(
        public readonly int $rpcCode,
        string $message,
        public readonly mixed $data = Undefined::Value,
    ) {
        parent::__construct($message, $rpcCode);
    }
}

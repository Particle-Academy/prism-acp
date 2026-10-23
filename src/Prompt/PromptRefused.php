<?php

declare(strict_types=1);

namespace Prism\Acp\Prompt;

/**
 * A prompt a driver will not send, naming the block. The WHOLE prompt is refused: sending the sentence without the
 * picture beside it would answer a question the person did not ask.
 */
final class PromptRefused extends \RuntimeException
{
    public function __construct(
        /** Zero-based position of the refused block. */
        public readonly int $index,
        /** What the block was, as far as it could be read (`image/bmp`, `resource_link`, ...). */
        public readonly string $block,
        string $reason,
    ) {
        parent::__construct('prompt refused at block '.($index + 1)." ({$block}): {$reason}");
    }
}

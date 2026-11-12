<?php

declare(strict_types=1);

namespace App\Tests\Integration\S3;

use Closure;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * A request body that hands out its first $afterBytes normally and then calls $onStall before the next read, so a
 * request can be cut in the middle of its body (see FaultInjectingHttpHandler::stallOn()).
 */
final class StallingStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /** @var StreamInterface */
    private $stream;

    private int $delivered = 0;

    /**
     * @param Closure(int): void $onStall
     */
    public function __construct(StreamInterface $stream, private readonly int $afterBytes, private readonly Closure $onStall)
    {
        $this->stream = $stream;
    }

    public function read(int $length): string
    {
        if ($this->delivered >= $this->afterBytes) {
            ($this->onStall)($this->delivered);
        }
        $data = $this->stream->read(min($length, max(1, $this->afterBytes - $this->delivered)));
        $this->delivered += strlen($data);

        return $data;
    }
}

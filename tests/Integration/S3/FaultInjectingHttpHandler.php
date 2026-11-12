<?php

declare(strict_types=1);

namespace App\Tests\Integration\S3;

use App\Tests\Support\TempFiles;
use Aws\Handler\Guzzle\GuzzleHandler;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * HTTP handler for the S3 SDK (client option "http_handler") that passes requests to the real test server, except the
 * ones a test chose to break: an error response, a connection that drops before the server sees the request, or a
 * response that gets lost after the server did the work. It can also stall a request halfway through its body
 * (stallOn), so a test can kill the process at that moment. It records each operation and the bytes held in temporary
 * files at the moment a request leaves.
 */
final class FaultInjectingHttpHandler
{
    /** @var list<string> */
    public array $operations = [];

    /** Most bytes held in temporary files when any request was sent. */
    public int $maxTempBytes = 0;

    /** @var array<string, array<int, Closure(RequestInterface, array<string, mixed>): PromiseInterface>> */
    private array $faults = [];

    /** @var array<string, int> */
    private array $counts = [];

    private readonly GuzzleHandler $inner;

    public function __construct()
    {
        $this->inner = new GuzzleHandler();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $operation = self::operation($request);
        $this->operations[] = $operation;
        $count = $this->counts[$operation] = ($this->counts[$operation] ?? 0) + 1;
        $this->maxTempBytes = max($this->maxTempBytes, TempFiles::bytesHeld());

        $fault = $this->faults[$operation][$count] ?? null;
        unset($this->faults[$operation][$count]);

        return $fault !== null ? $fault($request, $options) : ($this->inner)($request, $options);
    }

    /**
     * Breaks the n-th request (counted from 1) of an operation, e.g. the second UploadPart.
     *
     * @param Closure(RequestInterface, array<string, mixed>): PromiseInterface $fault
     */
    public function failOn(string $operation, int $nth, Closure $fault): void
    {
        $this->faults[$operation][$nth] = $fault;
    }

    public function count(string $operation): int
    {
        return $this->counts[$operation] ?? 0;
    }

    /**
     * Sends the n-th request of an operation, but calls $onStall once curl has read $afterBytes of its body. The
     * callback is meant to block (and the process to be killed meanwhile), so the request is cut in the middle.
     *
     * @param Closure(int): void $onStall gets the bytes handed to curl so far
     */
    public function stallOn(string $operation, int $nth, int $afterBytes, Closure $onStall): void
    {
        $this->faults[$operation][$nth] = fn (RequestInterface $request, array $options): PromiseInterface => ($this->inner)(
            $request->withBody(new StallingStream($request->getBody(), $afterBytes, $onStall)),
            $options,
        );
    }

    /**
     * The server answers with an S3 error document. Like the SDK's Guzzle handler, an error status rejects the
     * promise; the SDK then parses the error from the response.
     *
     * @param array<string, string> $headers
     *
     * @return Closure(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public static function errorResponse(int $status, string $code, array $headers = []): Closure
    {
        return static function (RequestInterface $request) use ($status, $code, $headers): PromiseInterface {
            $response = new Response(
                $status,
                $headers + ['Content-Type' => 'application/xml'],
                sprintf('<?xml version="1.0" encoding="UTF-8"?><Error><Code>%s</Code><Message>Injected by the test</Message><RequestId>injected</RequestId></Error>', $code),
            );

            return Create::rejectionFor([
                'exception' => RequestException::create($request, $response),
                'connection_error' => false,
                'response' => $response,
            ]);
        };
    }

    /**
     * The connection drops before the server sees the request.
     *
     * @return Closure(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public static function connectionLost(): Closure
    {
        return static fn (RequestInterface $request): PromiseInterface => self::connectionError($request, 'Injected: connection reset');
    }

    /**
     * The server handles the request, but its response never reaches the client.
     *
     * @return Closure(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public function responseLost(): Closure
    {
        return fn (RequestInterface $request, array $options): PromiseInterface => ($this->inner)($request, $options)
            ->then(static fn (): PromiseInterface => self::connectionError($request, 'Injected: response lost'));
    }

    private static function connectionError(RequestInterface $request, string $message): PromiseInterface
    {
        return Create::rejectionFor([
            'exception' => new ConnectException($message, $request),
            'connection_error' => true,
            'response' => null,
        ]);
    }

    private static function operation(RequestInterface $request): string
    {
        parse_str($request->getUri()->getQuery(), $query);
        $method = $request->getMethod();

        return match (true) {
            $method === 'POST' && array_key_exists('uploads', $query) => 'CreateMultipartUpload',
            $method === 'POST' && isset($query['uploadId']) => 'CompleteMultipartUpload',
            $method === 'PUT' && isset($query['partNumber']) => 'UploadPart',
            $method === 'GET' && isset($query['uploadId']) => 'ListParts',
            $method === 'DELETE' && isset($query['uploadId']) => 'AbortMultipartUpload',
            $method === 'HEAD' => 'HeadObject',
            $method === 'GET' => 'GetObject',
            $method === 'DELETE' => 'DeleteObject',
            default => $method,
        };
    }
}

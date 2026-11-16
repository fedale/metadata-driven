<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The requests of the http_request step: never to a private network (localhost, private and
 * link-local addresses, the clouds' metadata), at most 1 MB read, tried again on a network error
 * or a 5xx after a pause doubled each time.
 */
final class WorkflowHttp
{
    public const int MAX_RESPONSE_BYTES = 1_048_576;

    private readonly HttpClientInterface $http;

    public function __construct(
        HttpClientInterface $http,
        /* The tests' fake hosts resolve nowhere: the guard would block them. */
        #[Autowire(param: 'app.workflow.allow_private_networks')]
        bool $allowPrivateNetworks = false,
        /** The first pause before a request is tried again, in milliseconds. */
        #[Autowire(param: 'app.workflow.http_retry_delay_ms')]
        private readonly int $retryDelayMs = 1000,
    ) {
        $this->http = $allowPrivateNetworks ? $http : new NoPrivateNetworkHttpClient($http);
    }

    /**
     * @param array<string, mixed> $options HttpClient's
     *
     * @return array{status: int, headers: array<string, string>, content: string, type: string}
     *
     * @throws StepFailed
     */
    public function send(string $method, string $url, array $options, int $retries): array
    {
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        if (!\in_array(\is_string($scheme) ? strtolower($scheme) : null, ['http', 'https'], true)) {
            throw new StepFailed(\sprintf('"%s" is no http or https address.', $url));
        }
        for ($try = 0;; ++$try) {
            try {
                $response = $this->http->request($method, $url, $options);
                $status = $response->getStatusCode();
                if ($status >= 500 && $try < $retries) {
                    $this->pause($try);
                    continue;
                }
                $headers = $response->getHeaders(false);
                $length = $headers['content-length'][0] ?? null;
                if (is_numeric($length) && (int) $length > self::MAX_RESPONSE_BYTES) {
                    throw new StepFailed(\sprintf('The response of %s is over 1 MB.', $url));
                }
                $content = $response->getContent(false);
                if (\strlen($content) > self::MAX_RESPONSE_BYTES) {
                    throw new StepFailed(\sprintf('The response of %s is over 1 MB.', $url));
                }

                return [
                    'status' => $status,
                    'headers' => array_map(static fn (array $values): string => implode(', ', $values), $headers),
                    'content' => $content,
                    'type' => $headers['content-type'][0] ?? '',
                ];
            } catch (TransportExceptionInterface $e) {
                if ($try < $retries) {
                    $this->pause($try);
                    continue;
                }
                throw new StepFailed(\sprintf('%s %s: %s', $method, $url, $e->getMessage()), 0, $e);
            }
        }
    }

    private function pause(int $try): void
    {
        usleep($this->retryDelayMs * 1000 * (2 ** $try));
    }
}

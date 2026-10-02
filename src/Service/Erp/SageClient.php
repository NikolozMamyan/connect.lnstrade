<?php
namespace App\Service\Erp;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SageClient
{
    private const MAX_RATE_LIMIT_RETRIES = 2;

    private string $baseUri;
    private string $sageUsername;
    private string $sagePassword;
    private ?string $sageClientId;
    private ?string $sageClientSecret;
    private ?string $accessToken = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        ParameterBagInterface $parameters,
        private readonly ?SageApiRateLimiter $rateLimiter = null,
    ) {
        $this->baseUri = rtrim($parameters->get('base_uri_sage'), '/');
        $this->sageUsername = $parameters->get('sage_username');
        $this->sagePassword = $parameters->get('sage_password');
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     */
    public function get(string $uri, array $query = [], array $headers = []): array
    {
        return $this->request('GET', $uri, [
            'query' => $query,
            'headers' => $headers,
        ]);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     */
    public function post(string $uri, array $body = [], array $headers = []): array
    {
        return $this->request('POST', $uri, [
            'json' => $body,
            'headers' => $headers,
        ]);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     */
    public function put(string $uri, array $body = [], array $headers = []): array
    {
        return $this->request('PUT', $uri, [
            'json' => $body,
            'headers' => $headers,
        ]);
    }

        public function patch(string $uri, array $body = [], array $headers = []): array
    {
        return $this->request('PATCH', $uri, [
            'json' => $body,
            'headers' => $headers,
        ]);
    }


    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     */
    public function delete(string $uri, array $body = [], array $headers = []): array
    {
        $options = [
            'headers' => $headers,
        ];

        if ([] !== $body) {
            $options['json'] = $body;
        }

        return $this->request('DELETE', $uri, $options);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     */
    public function request(string $method, string $uri, array $options = []): array
    {
        $options['headers'] = array_merge(
            [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->getAccessToken(),
            ],
            $options['headers'] ?? []
        );

        $response = $this->send(
            $method,
            $this->buildUrl($uri),
            $options
        );

        if (401 === $response->getStatusCode()) {
            $this->accessToken = null;

            $options['headers']['Authorization'] = 'Bearer '.$this->getAccessToken();

            $response = $this->send(
                $method,
                $this->buildUrl($uri),
                $options
            );
        }

        return $this->decodeResponse($response);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     */
    private function getAccessToken(): string
    {
        if (null !== $this->accessToken) {
            return $this->accessToken;
        }

        $this->accessToken = $this->authenticate();

        return $this->accessToken;
    }

    /**
     * À adapter selon le vrai endpoint d'auth de ton API Sage.
     *
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     */
    private function authenticate(): string
    {
        $response = $this->send('POST', $this->buildUrl('/auth/login'), [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'json' => array_filter([
                'username' => $this->sageUsername,
                'password' => $this->sagePassword,

            ], static fn (mixed $value): bool => null !== $value && '' !== $value),
        ]);

        $data = $this->decodeResponse($response);

        $token = $data['accessToken']
            ?? $data['refreshToken']
            ?? $data['jwt']
            ?? null;

        if (!\is_string($token) || '' === $token) {
            throw new \RuntimeException('Impossible de récupérer le token Sage.');
        }

        return $token;
    }

    private function send(string $method, string $url, array $options): ResponseInterface
    {
        for ($attempt = 0; ; ++$attempt) {
            $this->rateLimiter?->acquire();
            $response = $this->httpClient->request($method, $url, $options);

            if (429 !== $response->getStatusCode()
                || null === $this->rateLimiter
                || $attempt >= self::MAX_RATE_LIMIT_RETRIES
            ) {
                return $response;
            }

            $retryAfter = $this->getRetryAfterSeconds($response);
            $response->getContent(false);
            $this->rateLimiter->blockFor($retryAfter);
        }
    }

    private function getRetryAfterSeconds(ResponseInterface $response): int
    {
        $headers = $response->getHeaders(false);
        $value = $headers['retry-after'][0] ?? null;

        if (\is_string($value) && is_numeric($value)) {
            return max(1, (int) ceil((float) $value));
        }

        if (\is_string($value)) {
            $retryAt = strtotime($value);

            if (false !== $retryAt) {
                return max(1, $retryAt - time());
            }
        }

        return 60;
    }

    /**
     * @throws DecodingExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Erreur API Sage [%d] : %s',
                $statusCode,
                $content
            ));
        }

        if ('' === $content) {
            return [];
        }

        $data = $response->toArray(false);

        if (!\is_array($data)) {
            throw new \RuntimeException('Réponse JSON invalide de l’API Sage.');
        }

        return $data;
    }

    private function buildUrl(string $uri): string
    {
        return $this->baseUri.'/'.ltrim($uri, '/');
    }
}

<?php

namespace App\Service\HubSpot;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HubSpotClient
{
    private string $baseUri;
    private string $hubspotAccess;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        ParameterBagInterface $parameters,
    ) {
        $this->baseUri = rtrim($parameters->get('base_uri_hubspot'), '/');
        $this->hubspotAccess = $parameters->get('hubspot_access');
    }

    /**
     * Liste les objets HubSpot d'un type donné.
     *
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws DecodingExceptionInterface
     */
    public function listObjects(string $objectType, array $query = []): array
    {
        $query = $this->normalizeQuery($query);

        $response = $this->httpClient->request('GET', sprintf('%s/crm/objects/v3/%s', $this->baseUri, $objectType), [
            'headers' => $this->getHeaders(),
            'query' => $query,
        ]);

        return $response->toArray(false);
    }

    /**
     * Récupération générique GET.
     *
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws DecodingExceptionInterface
     */
    public function get(string $path, array $query = []): array
    {
        $query = $this->normalizeQuery($query);

        $response = $this->httpClient->request('GET', $this->buildUrl($path), [
            'headers' => $this->getHeaders(),
            'query' => $query,
        ]);

        return $response->toArray(false);
    }

    /**
     * Création générique POST.
     *
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws DecodingExceptionInterface
     */
    public function post(string $path, array $body = []): array
    {
        $response = $this->httpClient->request('POST', $this->buildUrl($path), [
            'headers' => $this->getHeaders(),
            'json' => $body,
        ]);

        return $response->toArray(false);
    }
    /**
 * Mise à jour générique PATCH.
 *
 * @throws TransportExceptionInterface
 * @throws ClientExceptionInterface
 * @throws RedirectionExceptionInterface
 * @throws ServerExceptionInterface
 * @throws DecodingExceptionInterface
 */
public function patch(string $path, array $body = [], array $query = []): array
{
    $query = $this->normalizeQuery($query);

    $response = $this->httpClient->request('PATCH', $this->buildUrl($path), [
        'headers' => $this->getHeaders(),
        'query' => $query,
        'json' => $body,
    ]);

    return $response->toArray(false);
}

    /**
     * Crée un objet HubSpot.
     *
     * $properties = [
     *     'firstname' => 'John',
     *     'lastname' => 'Doe',
     *     'email' => 'john@doe.com',
     * ];
     *
     * $associations = [
     *     [
     *         'to' => [
     *             'id' => '123456',
     *         ],
     *         'types' => [
     *             [
     *                 'associationCategory' => 'HUBSPOT_DEFINED',
     *                 'associationTypeId' => 1,
     *             ],
     *         ],
     *     ],
     * ];
     *
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws DecodingExceptionInterface
     */
    public function createObject(string $objectType, array $properties, array $associations = []): array
    {
        $body = [
            'properties' => $properties,
        ];

        if (!empty($associations)) {
            $body['associations'] = $associations;
        }

        return $this->post(sprintf('/crm/objects/v3/%s', $objectType), $body);
    }
    /**
 * Met à jour partiellement un objet HubSpot.
 *
 * Exemple :
 * $hubSpotClient->updateObject('contacts', '123', [
 *     'firstname' => 'Jean',
 *     'lastname' => 'Dupont',
 * ]);
 *
 * Avec idProperty :
 * $hubSpotClient->updateObject('companies', 'ERP001', [
 *     'name' => 'ACME SAS',
 * ], [
 *     'idProperty' => 'id_erp',
 * ]);
 *
 * @throws TransportExceptionInterface
 * @throws ClientExceptionInterface
 * @throws RedirectionExceptionInterface
 * @throws ServerExceptionInterface
 * @throws DecodingExceptionInterface
 */
public function updateObject(string $objectType, string $objectId, array $properties, array $query = []): array
{
    if ([] === $properties) {
        throw new \InvalidArgumentException('HubSpot updateObject requires at least one property.');
    }

    $body = [
        'properties' => $properties,
    ];

    return $this->patch(
        sprintf('/crm/objects/v3/%s/%s', $objectType, $objectId),
        $body,
        $query
    );
}
/**
 * Recherche des objets HubSpot avec filtres, tri et pagination.
 *
 * @throws TransportExceptionInterface
 * @throws ClientExceptionInterface
 * @throws RedirectionExceptionInterface
 * @throws ServerExceptionInterface
 * @throws DecodingExceptionInterface
 */
public function searchObjects(string $objectType, array $payload = []): array
{
    $body = $this->normalizeSearchPayload($payload);

    return $this->post(
        sprintf('/crm/objects/v3/%s/search', $objectType),
        $body
    );
}

private function normalizeSearchPayload(array $payload): array
{
    if (isset($payload['limit'])) {
        $payload['limit'] = max(1, min(200, (int) $payload['limit']));
    }

    foreach (['properties', 'sorts'] as $key) {
        if (isset($payload[$key]) && !is_array($payload[$key])) {
            $payload[$key] = [$payload[$key]];
        }
    }

    return array_filter(
        $payload,
        static fn ($value) => $value !== null && $value !== '' && $value !== []
    );
}
    /**
 * Récupère un objet HubSpot par ID.
 *
 * Exemple :
 * $hubspot->getObject('contacts', '123', [
 *     'properties' => ['firstname', 'lastname', 'email'],
 *     'associations' => ['companies']
 * ]);
 *
 * @throws TransportExceptionInterface
 * @throws ClientExceptionInterface
 * @throws RedirectionExceptionInterface
 * @throws ServerExceptionInterface
 * @throws DecodingExceptionInterface
 */
public function getObject(string $objectType, string $objectId, array $query = []): array
{
    $query = $this->normalizeQuery($query);

    $response = $this->httpClient->request(
        'GET',
        sprintf('%s/crm/objects/v3/%s/%s', $this->baseUri, $objectType, $objectId),
        [
            'headers' => $this->getHeaders(),
            'query' => $query,
        ]
    );

    return $response->toArray(false);
}

    private function getHeaders(): array
    {
        return [
            'Authorization' => sprintf('Bearer %s', $this->hubspotAccess),
            'Content-Type' => 'application/json',
        ];
    }

    private function buildUrl(string $path): string
    {
        return str_starts_with($path, '/')
            ? $this->baseUri . $path
            : $this->baseUri . '/' . $path;
    }

    private function normalizeQuery(array $query): array
    {
        $csvKeys = [
            'properties',
            'propertiesWithHistory',
            'associations',
        ];

        foreach ($csvKeys as $key) {
            if (isset($query[$key]) && is_array($query[$key])) {
                $query[$key] = implode(',', array_filter(
                    $query[$key],
                    static fn ($value) => $value !== null && $value !== ''
                ));
            }
        }

        if (isset($query['archived'])) {
            $query['archived'] = filter_var($query['archived'], FILTER_VALIDATE_BOOL);
        }

        return array_filter(
            $query,
            static fn ($value) => $value !== null && $value !== ''
        );
    }
}
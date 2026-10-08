<?php

namespace App\Tests\Controller;

use App\Controller\DeliveryTrackingController;
use App\Entity\User;
use App\Repository\HubspotCompanyRepository;
use App\Service\Erp\SageClient;
use App\Service\Erp\SageDeliveryTrackingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class DeliveryTrackingControllerTest extends TestCase
{
    #[DataProvider('ownerFilters')]
    public function testCommercialCanTrackOtherOwners(string $owner, array $expectedPieces): void
    {
        $queries = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$queries): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ($method === 'POST' && $path === '/auth/login') {
                return new MockResponse('{"accessToken":"test-token"}');
            }

            self::assertSame('GET', $method);
            self::assertSame('/Document/header', $path);
            self::assertArrayNotHasKey('representant', $query);
            $queries[] = $query;

            return new MockResponse(json_encode((int) $query['type'] === 1 ? [
                ['piece' => 'BC001', 'date' => '2026-10-08', 'tiers' => 'CLI-1', 'representant' => 'CHAOUI Anthony'],
                ['piece' => 'BC002', 'date' => '2026-10-08', 'tiers' => 'CLI-2', 'representant' => 'WOODS Douglas'],
            ] : [], JSON_THROW_ON_ERROR));
        });
        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository->expects(self::once())->method('findNamesIndexedByErpIds')->willReturn([]);
        $trackingService = new SageDeliveryTrackingService(
            new SageClient($httpClient, new ParameterBag([
                'base_uri_sage' => 'https://sage.test',
                'sage_username' => 'user',
                'sage_password' => 'password',
            ])),
            $companyRepository,
        );

        $user = (new User())->setEmail('anthony.chaoui@lnstrade.fr')->setRoles(['ROLE_COM']);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['layout/app.html.twig' => '{% block content %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $parameters = []): string => '/suivi-livraison?' . http_build_query($parameters)));
        $container = new Container();
        $container->set('twig', $twig);
        $container->set('security.token_storage', $tokenStorage);
        $controller = new DeliveryTrackingController();
        $controller->setContainer($container);

        $response = $controller->index(Request::create('/suivi-livraison', 'GET', ['owner' => $owner]), $trackingService);
        $crawler = new Crawler($response->getContent());

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(4, $queries);
        self::assertSame($expectedPieces, $crawler->filter('.delivery-piece__number')->extract(['_text']));
        self::assertSame(['', 'CHAOUI Anthony', 'WOODS Douglas'], $crawler->filter('#delivery-owner option')->extract(['value']));

        if ($owner !== '') {
            self::assertSame($owner, $crawler->filter('#delivery-owner option[selected]')->attr('value'));
        }
    }

    public static function ownerFilters(): iterable
    {
        yield 'all owners' => ['', ['BC002', 'BC001']];
        yield 'another owner' => ['WOODS Douglas', ['BC002']];
    }
}

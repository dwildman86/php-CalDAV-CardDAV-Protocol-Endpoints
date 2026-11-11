<?php

declare(strict_types=1);

namespace GhostParse\Dav;

use GhostParse\Dav\Auth\AuthBackendInterface;
use GhostParse\Dav\Backend\AddressBookBackendInterface;
use GhostParse\Dav\Backend\CalendarBackendInterface;
use GhostParse\Dav\Backend\PrincipalBackendInterface;
use GhostParse\Dav\Handler\CollectionHandler;
use GhostParse\Dav\Handler\ContentHandler;
use GhostParse\Dav\Handler\Context;
use GhostParse\Dav\Handler\PropfindHandler;
use GhostParse\Dav\Handler\ProppatchHandler;
use GhostParse\Dav\Handler\ReportHandler;
use GhostParse\Dav\Http\HttpException;
use GhostParse\Dav\Http\Request;
use GhostParse\Dav\Http\Response;
use GhostParse\Dav\Routing\Location;
use GhostParse\Dav\Routing\NodeType;
use GhostParse\Dav\Routing\Router;
use GhostParse\Dav\Routing\Tree;
use GhostParse\Dav\Xml\Ns;
use GhostParse\Dav\Xml\XmlWriter;

/**
 * CalDAV / CardDAV server entry point.
 *
 *     $server = new Server($users, $users, $calendars, $addressBooks, baseUri: '/dav/');
 *     $server->run();
 */
final class Server
{
    public const VERSION = '1.0.0';

    private Router $router;

    /** @var (callable(\Throwable): void)|null */
    private $errorLogger = null;

    public function __construct(
        private AuthBackendInterface $authBackend,
        private PrincipalBackendInterface $principalBackend,
        private ?CalendarBackendInterface $calendarBackend = null,
        private ?AddressBookBackendInterface $addressBookBackend = null,
        string $baseUri = '/',
        private string $realm = 'GhostParse DAV',
        private bool $autoProvision = true,
        private int $maxResourceSize = 10 * 1024 * 1024,
        private bool $debug = false,
    ) {
        if ($calendarBackend === null && $addressBookBackend === null) {
            throw new \InvalidArgumentException('At least one of the calendar or address book backends must be provided');
        }
        $this->router = new Router($baseUri, $calendarBackend !== null, $addressBookBackend !== null);
    }

    /**
     * Register a callable that receives unexpected exceptions (e.g. to log them).
     *
     * @param callable(\Throwable): void $logger
     */
    public function setErrorLogger(callable $logger): void
    {
        $this->errorLogger = $logger;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Handle the current PHP request and send the response.
     */
    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }

    /**
     * Handle a request and return the response without sending it.
     */
    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (HttpException $e) {
            $response = $this->errorResponse($e);
        } catch (\Throwable $e) {
            if ($this->errorLogger !== null) {
                ($this->errorLogger)($e);
            }
            $response = $this->errorResponse(new HttpException(500, $this->debug ? $e->getMessage() : 'Internal Server Error'));
        }

        $response->setHeader('DAV', $this->davHeader());
        if ($request->getMethod() === 'HEAD') {
            if ($response->getHeader('Content-Length') === null) {
                $response->setHeader('Content-Length', (string) strlen($response->getBody()));
            }
            $response->setBody('');
        }

        return $response;
    }

    private function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        // RFC 6764 service discovery.
        if (preg_match('#/\.well-known/(caldav|carddav)/?$#', $path) === 1) {
            return new Response(301, ['Location' => $this->router->getBaseUri(), 'Content-Length' => '0']);
        }

        $location = $this->router->resolve($path) ?? throw new HttpException(404, 'Not Found');

        if ($method === 'OPTIONS') {
            return new Response(200, [
                'Allow' => implode(', ', $this->allowedMethods($location)),
                'Content-Length' => '0',
            ]);
        }

        if (!in_array($method, $this->allowedMethods($location), true)) {
            $known = ['GET', 'HEAD', 'PUT', 'DELETE', 'PROPFIND', 'PROPPATCH', 'MKCOL', 'MKCALENDAR', 'REPORT', 'COPY', 'MOVE', 'LOCK', 'UNLOCK', 'POST', 'ACL'];
            if (!in_array($method, $known, true)) {
                throw new HttpException(501, 'Method not implemented');
            }
            throw new HttpException(405, 'Method not allowed on this resource', null, [
                'Allow' => implode(', ', $this->allowedMethods($location)),
            ]);
        }

        $user = $this->authenticate($request);
        $this->checkAccess($location, $user, $method);

        if ($this->autoProvision && $method === 'PROPFIND') {
            $this->provision($user);
        }

        $context = new Context(
            $this->router,
            new Tree($this->router, $this->principalBackend, $this->calendarBackend, $this->addressBookBackend, $user),
            $user,
            $this->principalBackend,
            $this->calendarBackend,
            $this->addressBookBackend,
            $this->maxResourceSize,
        );

        return match ($method) {
            'PROPFIND' => (new PropfindHandler($context))->handle($request, $location),
            'PROPPATCH' => (new ProppatchHandler($context))->handle($request, $location),
            'GET', 'HEAD' => (new ContentHandler($context))->get($request, $location),
            'PUT' => (new ContentHandler($context))->put($request, $location),
            'DELETE' => $location->type->isCollection()
                ? (new CollectionHandler($context))->delete($location)
                : (new ContentHandler($context))->delete($request, $location),
            'MKCALENDAR' => (new CollectionHandler($context))->mkcalendar($request, $location),
            'MKCOL' => (new CollectionHandler($context))->mkcol($request, $location),
            'REPORT' => (new ReportHandler($context))->handle($request, $location),
        };
    }

    /**
     * @return list<string>
     */
    private function allowedMethods(Location $location): array
    {
        $base = ['OPTIONS', 'PROPFIND'];

        return match ($location->type) {
            NodeType::Root => [...$base, 'GET', 'HEAD'],
            NodeType::Calendar => [...$base, 'PROPPATCH', 'GET', 'HEAD', 'DELETE', 'MKCALENDAR', 'MKCOL', 'REPORT'],
            NodeType::AddressBook => [...$base, 'PROPPATCH', 'GET', 'HEAD', 'DELETE', 'MKCOL', 'REPORT'],
            NodeType::CalendarObject, NodeType::Card => [...$base, 'GET', 'HEAD', 'PUT', 'DELETE', 'REPORT'],
            default => $base,
        };
    }

    private function authenticate(Request $request): string
    {
        $header = (string) $request->getHeader('Authorization');
        if (strncasecmp($header, 'Basic ', 6) === 0) {
            $decoded = base64_decode(trim(substr($header, 6)), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$username, $password] = explode(':', $decoded, 2);
                if ($username !== '' && $this->authBackend->authenticate($username, $password)) {
                    return $username;
                }
            }
        }

        throw new HttpException(401, 'Authentication required', null, [
            'WWW-Authenticate' => sprintf('Basic realm="%s", charset="UTF-8"', addcslashes($this->realm, '"\\')),
        ]);
    }

    private function checkAccess(Location $location, string $user, string $method): void
    {
        if ($location->type->isUserScoped() && $location->user !== $user) {
            throw new HttpException(403, 'You do not have access to this resource', '{DAV:}need-privileges');
        }
        if ($location->type === NodeType::Principal && $location->user !== $user && $method !== 'PROPFIND') {
            throw new HttpException(403, 'You do not have access to this resource', '{DAV:}need-privileges');
        }
    }

    /**
     * Create a default calendar and address book for users that have none.
     */
    private function provision(string $user): void
    {
        if ($this->calendarBackend !== null && $this->calendarBackend->getCalendarsForUser($user) === []) {
            $this->calendarBackend->createCalendar($user, 'default', [
                'displayName' => 'Calendar',
                'components' => ['VEVENT', 'VTODO'],
            ]);
        }
        if ($this->addressBookBackend !== null && $this->addressBookBackend->getAddressBooksForUser($user) === []) {
            $this->addressBookBackend->createAddressBook($user, 'default', ['displayName' => 'Contacts']);
        }
    }

    private function davHeader(): string
    {
        $classes = ['1', '3', 'extended-mkcol'];
        if ($this->calendarBackend !== null) {
            $classes[] = 'calendar-access';
        }
        if ($this->addressBookBackend !== null) {
            $classes[] = 'addressbook';
        }

        return implode(', ', $classes);
    }

    private function errorResponse(HttpException $e): Response
    {
        $writer = new XmlWriter();
        $root = $writer->root('{DAV:}error');
        if ($e->getCondition() !== null) {
            $root->appendChild($writer->element($e->getCondition()));
        }
        $root->appendChild($writer->element('{' . Ns::GHOSTPARSE . '}message', $e->getMessage()));

        $response = new Response($e->getStatus(), ['Content-Type' => 'application/xml; charset=utf-8'], $writer->toString());
        foreach ($e->getHeaders() as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
    }
}

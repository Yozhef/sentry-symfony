<?php

namespace Sentry\SentryBundle\Test\End2End;

use Sentry\SentryBundle\Test\End2End\App\Kernel;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;

if (! class_exists(KernelBrowser::class)) {
    class_alias(Client::class, KernelBrowser::class);
}

/**
 * @runTestsInSeparateProcesses
 */
class End2EndTest extends WebTestCase
{
    public const SENT_EVENTS_LOG = '/tmp/sentry_e2e_test_sent_events.log';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        file_put_contents(self::SENT_EVENTS_LOG, '');
    }

    public function testGet200(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/200');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertLastEventIdIsNotNull($client);
        $this->assertEventCount(1);
    }

    public function testGet200BehindFirewall(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/secured/200');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertLastEventIdIsNotNull($client);
        $this->assertEventCount(1);
    }

    public function testGet200WithSubrequest(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/subrequest');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertLastEventIdIsNotNull($client);
        $this->assertEventCount(1);
    }

    public function testGet404(): void
    {
        $client = static::createClient(['debug' => false]);

        try {
            $client->request('GET', '/missing-page');

            $response = $client->getResponse();

            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(404, $response->getStatusCode());
        } catch (\Throwable $exception) {
            if (! $exception instanceof NotFoundHttpException) {
                throw $exception;
            }

            $this->assertSame('No route found for "GET /missing-page"', $exception->getMessage());
        }

        $this->assertLastEventIdIsNotNull($client);
        $this->assertEventCount(1);
    }

    public function testGet500(): void
    {
        $client = static::createClient();

        try {
            $client->request('GET', '/exception');

            $response = $client->getResponse();

            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(500, $response->getStatusCode());
            $this->assertStringContainsString('intentional error', $response->getContent() ?: '');
        } catch (\Throwable $exception) {
            if (! $exception instanceof \RuntimeException) {
                throw $exception;
            }

            $this->assertSame('This is an intentional error', $exception->getMessage());
        }

        $this->assertLastEventIdIsNotNull($client);
        $this->assertEventCount(1);
    }

    /**
     * @requires PHP >= 7.3
     */
    public function testGetFatal(): void
    {
        $client = static::createClient();

        try {
            $client->insulate(true);
            $client->request('GET', '/fatal');

            $response = $client->getResponse();

            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(500, $response->getStatusCode());
            $this->assertStringNotContainsString('not happen', $response->getContent() ?: '');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsStringIgnoringCase('error', $exception->getMessage());
            $this->assertStringContainsStringIgnoringCase('contains 2 abstract methods', $exception->getMessage());
            $this->assertStringContainsStringIgnoringCase('MainController.php', $exception->getMessage());
            $this->assertStringContainsStringIgnoringCase('eval()\'d code on line', $exception->getMessage());
        }

        $this->assertEventCount(1);
    }

    public function testNotice(): void
    {
        $client = static::createClient();
        $client->request('GET', '/notice');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertLastEventIdIsNotNull($client);
        $this->assertEventCount(1);
    }

    public function testMessengerCaptureHardFailure(): void
    {
        $this->skipIfMessengerIsMissing();

        $client = static::createClient();

        $client->request('GET', '/dispatch-unrecoverable-message');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->consumeOneMessage($client->getKernel());

        $this->assertLastEventIdIsNotNull($client);
    }

    public function testMessengerCaptureSoftFailCanBeDisabled(): void
    {
        $this->skipIfMessengerIsMissing();

        $client = static::createClient();

        $client->request('GET', '/dispatch-message');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->consumeOneMessage($client->getKernel());

        $this->assertLastEventIdIsNull($client);
    }

    private function consumeOneMessage(KernelInterface $kernel): void
    {
        $application = new Application($kernel);

        $command = $application->find('messenger:consume');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'receivers' => ['async'],
            '--limit' => 1,
            '--time-limit' => 1,
            '-vvv' => true,
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
    }

    private function assertLastEventIdIsNotNull(KernelBrowser $client): void
    {
        $container = $client->getContainer();
        $this->assertNotNull($container);

        $hub = $container->get('test.hub');
        $this->assertInstanceOf(HubInterface::class, $hub);

        $this->assertNotNull($hub->getLastEventId(), 'Last error not captured');
    }

    private function assertEventCount(int $expectedCount): void
    {
        $events = file_get_contents(self::SENT_EVENTS_LOG);
        $this->assertNotFalse($events, 'Cannot read sent events log');
        $listOfEvents = array_filter(explode(StubTransportFactory::SEPARATOR, trim($events)));
        $this->assertCount($expectedCount, $listOfEvents, 'Wrong number of events sent: ' . PHP_EOL . $events);
    }

    private function assertLastEventIdIsNull(KernelBrowser $client): void
    {
        $container = $client->getContainer();
        $this->assertNotNull($container);

        $hub = $container->get('test.hub');
        $this->assertInstanceOf(HubInterface::class, $hub);

        $this->assertNull($hub->getLastEventId(), 'Some error was captured');
    }

    private function skipIfMessengerIsMissing(): void
    {
        if (! interface_exists(MessageBusInterface::class) || Kernel::VERSION_ID < 40300) {
            $this->markTestSkipped('Messenger missing');
        }
    }
}

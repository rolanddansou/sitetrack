<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AnalyticsApiControllerTest extends WebTestCase
{
    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement("DELETE FROM messenger_messages WHERE body LIKE '%test-site%'");
        }
        $this->connection = null;
        parent::tearDown();
    }

    public function testMissingSiteIdOrPathReturns400(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/event', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['site_id' => 'test-site']));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testNonStringSiteIdReturns400InsteadOfCrashing(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/event',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['site_id' => 12345, 'path' => '/x'])
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testNonObjectJsonBodyReturns400InsteadOfCrashing(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/event', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode('just a string'));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testValidPayloadReturns204AndEnqueuesAMessage(): void
    {
        $client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);
        $before = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages');

        $client->request(
            'POST',
            '/api/event',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'site_id' => 'test-site',
                'path' => '/pricing',
                'utm_source' => 'twitter',
                'event_type' => 'event',
                'event_name' => 'signup',
                'props' => ['plan' => 'pro'],
            ])
        );

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHeaderSame('Access-Control-Allow-Origin', '*');

        $after = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages');
        $this->assertSame($before + 1, $after);
    }

    public function testValidPayloadWithScreenDimensionsEnqueuesThem(): void
    {
        $client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);

        $client->request(
            'POST',
            '/api/event',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'site_id' => 'test-site',
                'path' => '/',
                'screen_width' => 1920,
                'screen_height' => 1080,
            ])
        );

        $this->assertResponseStatusCodeSame(204);

        $body = (string) $this->connection->fetchOne('SELECT body FROM messenger_messages ORDER BY id DESC LIMIT 1');
        $this->assertStringContainsString('i:1920', $body);
        $this->assertStringContainsString('i:1080', $body);
    }

    public function testImplausibleScreenDimensionsAreDroppedInsteadOfStored(): void
    {
        $client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);

        $client->request(
            'POST',
            '/api/event',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'site_id' => 'test-site',
                'path' => '/',
                'screen_width' => -5,
                'screen_height' => 999999,
            ])
        );

        $this->assertResponseStatusCodeSame(204);

        $body = (string) $this->connection->fetchOne('SELECT body FROM messenger_messages ORDER BY id DESC LIMIT 1');
        $this->assertStringNotContainsString('i:-5', $body);
        $this->assertStringNotContainsString('i:999999', $body);
    }
}

<?php

declare(strict_types=1);

namespace Kreait\Firebase\JWT\Tests\Action\FetchGooglePublicKeys;

use Kreait\Firebase\JWT\Action\FetchGooglePublicKeys\Error\FetchingGooglePublicKeysFailed;
use Kreait\Firebase\JWT\Action\FetchGooglePublicKeys\Handler;
use Kreait\Firebase\JWT\Action\FetchGooglePublicKeys\WithPsr6Cache;
use Kreait\Firebase\JWT\Keys\ExpiringKeys;
use Kreait\Firebase\JWT\Keys\StaticKeys;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use stdClass;

final class WithPsr6CacheTest extends TestCase
{
    private $cache;
    private $cacheItem;

    private $inner;

    /** @var ExpiringKeys */
    private $expiringKeys;

    /** @var ExpiringKeys */
    private $expiredKeys;

    /** @var StaticKeys */
    private $nonExpiringKeys;

    public function setUp()
    {
        parent::setUp();

        $this->cacheItem = $this->createMock(CacheItemInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->cache->method('getItem')->willReturn($this->cacheItem);

        $this->inner = $this->createMock(Handler::class);

        $this->expiringKeys = ExpiringKeys::withValuesAndExpirationTime(['ir' => 'relevant'], $this->clock->now()->modify('+1 hour'));
        $this->expiredKeys = $this->expiringKeys->withExpirationTime($this->clock->now()->modify('-1 hour'));
        $this->nonExpiringKeys = StaticKeys::withValues(['ir' => 'relevant']);
    }

    protected function createHandler(): Handler
    {
        return new WithPsr6Cache($this->inner, $this->cache, $this->clock);
    }

    /** @test */
    public function it_caches_fresh_keys()
    {
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->inner->expects($this->once())->method('handle')->willReturn($this->expiringKeys);
        $this->cache->expects($this->once())->method('save');

        $this->assertSame($this->expiringKeys, $this->createHandler()->handle($this->action));
    }

    /** @test */
    public function it_returns_cached_non_expired_keys()
    {
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($this->expiringKeys);
        $this->inner->expects($this->never())->method($this->anything());

        $this->assertSame($this->expiringKeys, $this->createHandler()->handle($this->action));
    }

    /** @test */
    public function it_returns_cached_non_expiring_keys()
    {
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($this->nonExpiringKeys);
        $this->inner->expects($this->never())->method($this->anything());

        $this->assertSame($this->nonExpiringKeys, $this->createHandler()->handle($this->action));
    }

    /** @test */
    public function it_refreshes_expired_keys()
    {
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($this->expiredKeys);
        $this->inner->expects($this->once())->method('handle')->willReturn($this->expiringKeys);

        $this->assertSame($this->expiringKeys, $this->createHandler()->handle($this->action));
    }

    /** @test */
    public function it_handles_invalid_cache_contents()
    {
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn(new stdClass());

        $this->inner->expects($this->once())->method('handle')->willReturn($this->expiringKeys);

        $this->assertSame($this->expiringKeys, $this->createHandler()->handle($this->action));
    }

    /** @test */
    public function it_catches_errors_caused_by_the_inner_handler()
    {
        $innerError = FetchingGooglePublicKeysFailed::because('reason');
        $this->inner->method($this->anything())->willThrowException($innerError);

        try {
            $this->createHandler()->handle($this->action);
            $this->fail('An error should have been thrown');
        } catch (FetchingGooglePublicKeysFailed $e) {
            $this->assertNotSame($innerError, $e);
            $this->assertSame($innerError, $e->getPrevious());
        }
    }
}

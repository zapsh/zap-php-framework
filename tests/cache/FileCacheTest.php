<?php

namespace zap\tests\cache;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use zap\cache\FileCache;

/**
 * FileCache 的回归测试
 *
 * 重点覆盖两类曾经出问题的场景：
 *  1. ttl = 0（永久缓存，路由缓存依赖它）曾被误判为无效；
 *  2. 缓存文件被投毒时，反序列化不能还原出对象实例（POP 链防护）。
 */
final class FileCacheTest extends TestCase
{
    private string $dir;

    private FileCache $cache;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/zap_cache_' . getmypid();
        $this->cache = new FileCache(['cacheDir' => $this->dir, 'isCache' => 'enabled']);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
    }

    public function testSetAndGetRoundTrip(): void
    {
        $this->cache->set('key', ['a' => 1, 'b' => 'x'], 60);

        $this->assertSame(['a' => 1, 'b' => 'x'], $this->cache->get('key'));
    }

    public function testZeroTtlIsTreatedAsPermanent(): void
    {
        $this->cache->set('key', 'forever', 0);

        $this->assertSame('forever', $this->cache->get('key'));
    }

    public function testExpiredEntryIsDropped(): void
    {
        $this->cache->set('key', 'stale', 1);
        sleep(2);

        $this->assertNull($this->cache->get('key'));
    }

    public function testObjectPayloadIsNotInstantiated(): void
    {
        $file = $this->cacheFile('key');
        file_put_contents($file, 'O:13:"FakeBackdoor":1:{s:3:"cmd";s:1:"x";}');

        $this->assertNull($this->cache->get('key'));
        $this->assertFileDoesNotExist($file, '被投毒的缓存文件应被删除');
    }

    public function testCorruptedPayloadIsDiscarded(): void
    {
        $file = $this->cacheFile('key');
        file_put_contents($file, 'not-a-serialized-payload');

        $this->assertNull($this->cache->get('key'));
        $this->assertFileDoesNotExist($file);
    }

    /**
     * 取得缓存文件真实路径（getFilePath 为 protected）
     */
    private function cacheFile(string $key): string
    {
        $method = new ReflectionMethod($this->cache, 'getFilePath');
        $method->setAccessible(true);

        $path = $method->invoke($this->cache, $key);
        @mkdir(dirname($path), 0755, true);

        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

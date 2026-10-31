<?php

namespace zap\tests\db;

use PDO;
use PHPUnit\Framework\TestCase;
use zap\db\Query;
use zap\db\ZPDO;

/**
 * Query::limit() 的回归测试
 *
 * 背景：limit() 原先只接受一个参数，调用方写成 limit($size, $offset) 时
 * 第二个参数会被静默忽略，导致列表翻页始终返回第一页数据。
 */
final class QueryLimitTest extends TestCase
{
    private string $file;

    private ?ZPDO $db = null;

    protected function setUp(): void
    {
        // 文件名必须唯一：Windows 下被 PDO 持有的 sqlite 文件无法立即删除，
        // 复用同一个名字会让下一个用例建表时报 "table already exists"
        $this->file = sys_get_temp_dir() . '/zap_query_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
        @unlink($this->file);

        $setup = new PDO('sqlite:' . $this->file);
        $setup->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $stmt = $setup->prepare('INSERT INTO t (name) VALUES (?)');
        for ($i = 1; $i <= 30; $i++) {
            $stmt->execute(['row' . $i]);
        }
        unset($setup);

        $this->db = new ZPDO(['driver' => 'sqlite', 'dsn' => 'sqlite:' . $this->file, 'prefix' => '']);
    }

    protected function tearDown(): void
    {
        // 先断开连接再删文件，否则 Windows 下删除会失败
        $this->db = null;
        @unlink($this->file);
    }

    public function testLimitWithoutOffsetStillWorks(): void
    {
        $rows = (new Query($this->db, 't'))->limit(3)->get(PDO::FETCH_ASSOC);

        $this->assertSame(['row1', 'row2', 'row3'], array_column($rows, 'name'));
    }

    public function testLimitWithOffsetSkipsRows(): void
    {
        $query = new Query($this->db, 't');
        $query->limit(5, 10);

        $this->assertStringContainsString('LIMIT 5 OFFSET 10', $query->toSql());

        $rows = $query->get(PDO::FETCH_ASSOC);
        $this->assertSame(['row11', 'row12', 'row13', 'row14', 'row15'], array_column($rows, 'name'));
    }

    public function testChainedOffsetStillWorks(): void
    {
        $rows = (new Query($this->db, 't'))->limit(3)->offset(6)->get(PDO::FETCH_ASSOC);

        $this->assertSame(['row7', 'row8', 'row9'], array_column($rows, 'name'));
    }

    public function testPagingProducesDisjointPages(): void
    {
        $pageOne = (new Query($this->db, 't'))->limit(3, 0)->get(PDO::FETCH_ASSOC);
        $pageTwo = (new Query($this->db, 't'))->limit(3, 3)->get(PDO::FETCH_ASSOC);

        $this->assertSame(['row1', 'row2', 'row3'], array_column($pageOne, 'name'));
        $this->assertSame(['row4', 'row5', 'row6'], array_column($pageTwo, 'name'));
    }
}

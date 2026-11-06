<?php

namespace zap\tests\db;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use zap\db\Model;

/**
 * Model 批量赋值的回归测试
 *
 * 背景：filterFillable() 曾存在「保存中」（$saving）的例外放行分支，
 * 且 guarded 只认全等于 ['*'] 的写法（['id', '*'] 不生效）。
 */
class FillableStub extends Model
{
    protected $table = 'stub';

    protected $primaryKey = 'id';
}

final class ModelFillableTest extends TestCase
{
    public function testFillableActsAsWhitelist(): void
    {
        $model = $this->stubWith('fillable', ['name']);
        $model->fill(['name' => 'alice', 'is_admin' => 1]);

        $this->assertSame(['name' => 'alice'], $model->getAttributes());
    }

    public function testGuardedWildcardBlocksEverything(): void
    {
        $model = $this->stubWith('guarded', ['id', '*']);
        $model->fill(['name' => 'bob']);

        $this->assertSame([], $model->getAttributes());
    }

    public function testGuardedExcludesListedFieldsOnly(): void
    {
        $model = $this->stubWith('guarded', ['is_admin']);
        $model->fill(['name' => 'carol', 'is_admin' => 1]);

        $this->assertSame(['name' => 'carol'], $model->getAttributes());
    }

    public function testNoDeclarationKeepsBackwardCompatibleBehavior(): void
    {
        $model = new FillableStub();
        $model->fill(['name' => 'dave']);

        $this->assertSame(['name' => 'dave'], $model->getAttributes());
    }

    /**
     * fillable / guarded 是 protected 属性，测试里通过反射设置
     */
    private function stubWith(string $property, array $value): FillableStub
    {
        $model = new FillableStub();

        $reflection = new ReflectionProperty(Model::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($model, $value);

        return $model;
    }
}

<?php

namespace Tests\Queue\Model;

use Nails\Common\Model\Base as BaseModel;
use Nails\Queue\Constants;
use Nails\Queue\Model\Job;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nails\Queue\Model\Job
 */
class JobModelTest extends TestCase
{
    /**
     * Default sort should be id ASC.
     */
    public function test_default_sort_is_correct(): void
    {
        // Arrange
        // No model instance needed for constants

        // Act
        // Access constants directly

        // Assert
        self::assertSame('id', Job::DEFAULT_SORT_COLUMN);
        self::assertSame(BaseModel::SORT_ASC, Job::DEFAULT_SORT_ORDER);
    }

    /**
     * Caching should be disabled on the model.
     */
    public function test_caching_is_disabled(): void
    {
        // Arrange
        $reflection = new \ReflectionClass(Job::class);

        // Act
        $property = $reflection->getProperty('CACHING_ENABLED');
        $property->setAccessible(true);
        $value = $property->getValue();

        // Assert
        self::assertFalse($value);
    }

    /**
     * Worker relation should be a hasOne (expandable single) field.
     */
    public function test_has_worker_relation_is_single(): void
    {
        // Arrange
        $model = $this->getMockBuilder(Job::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        // Act
        // Invoke original constructor to register relations
        $constructorMethod = new \ReflectionMethod(Job::class, '__construct');
        $constructorMethod->invoke($model);
        $fields      = $model->getExpandableFields();
        $workerField = null;
        foreach ($fields as $field) {
            if ($field->trigger === 'worker') {
                $workerField = $field;
                break;
            }
        }

        // Assert
        self::assertNotNull($workerField);
        self::assertSame(BaseModel::EXPANDABLE_TYPE_SINGLE, $workerField->type);
        self::assertSame('Worker', $workerField->model);
        self::assertSame(Constants::MODULE_SLUG, $workerField->provider);
        self::assertSame('worker_id', $workerField->id_column);
        self::assertSame('worker', $workerField->property);
    }
}

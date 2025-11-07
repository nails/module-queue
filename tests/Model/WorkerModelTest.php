<?php

namespace Tests\Queue\Model;

use Nails\Common\Model\Base as BaseModel;
use Nails\Queue\Constants;
use Nails\Queue\Model\Worker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nails\Queue\Model\Worker
 */
class WorkerModelTest extends TestCase
{
    /**
     * Default sort should be id ASC.
     */
    public function test_default_sort_is_correct(): void
    {
        // Arrange
        // No instance needed

        // Act
        // Access constants

        // Assert
        self::assertSame('id', Worker::DEFAULT_SORT_COLUMN);
        self::assertSame(BaseModel::SORT_ASC, Worker::DEFAULT_SORT_ORDER);
    }

    /**
     * Caching should be disabled on the model.
     */
    public function test_caching_is_disabled(): void
    {
        // Arrange
        $reflection = new \ReflectionClass(Worker::class);

        // Act
        $property = $reflection->getProperty('CACHING_ENABLED');
        $property->setAccessible(true);
        $value = $property->getValue();

        // Assert
        self::assertFalse($value);
    }

    /**
     * Jobs relation should be a hasMany (expandable many) field.
     */
    public function test_has_jobs_relation_is_many(): void
    {
        // Arrange
        $model = $this->getMockBuilder(Worker::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        // Act
        $constructorMethod = new \ReflectionMethod(Worker::class, '__construct');
        $constructorMethod->invoke($model);
        $fields    = $model->getExpandableFields();
        $jobsField = null;
        foreach ($fields as $field) {
            if ($field->trigger === 'jobs') {
                $jobsField = $field;
                break;
            }
        }

        // Assert
        self::assertNotNull($jobsField);
        self::assertSame(BaseModel::EXPANDABLE_TYPE_MANY, $jobsField->type);
        self::assertSame('Job', $jobsField->model);
        self::assertSame(Constants::MODULE_SLUG, $jobsField->provider);
        self::assertSame('worker_id', $jobsField->id_column);
        self::assertSame('jobs', $jobsField->property);
    }
}

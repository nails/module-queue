<?php

namespace Tests\Queue\Factory;

use Nails\Queue\Factory\Data;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \Nails\Queue\Factory\Data
 */
class DataTest extends TestCase
{
    /**
     * Ensure scalar types are stored and retrieved as-is.
     */
    public function test_set_and_get_scalar_values(): void
    {
        // Arrange
        $stringData = new Data('foo');
        $intData    = new Data(123);
        $floatData  = new Data(12.34);
        $boolData   = new Data(true);
        $nullData   = new Data(null);

        // Act
        $gotString = $stringData->get();
        $gotInt    = $intData->get();
        $gotFloat  = $floatData->get();
        $gotBool   = $boolData->get();
        $gotNull   = $nullData->get();

        // Assert
        self::assertSame('foo', $gotString);
        self::assertSame(123, $gotInt);
        self::assertSame(12.34, $gotFloat);
        self::assertTrue($gotBool);
        self::assertNull($gotNull);
    }

    /**
     * Ensure arrays and stdClass objects are supported.
     */
    public function test_set_and_get_array_and_object(): void
    {
        // Arrange
        $arrayValue       = ['a' => 1, 'b' => [2, 3]];
        $objectValue      = new stdClass();
        $objectValue->one = 1;
        $objectValue->two = [2, 3];
        $arrayData        = new Data($arrayValue);
        $objectData       = new Data($objectValue);

        // Act
        $gotArray  = $arrayData->get();
        $gotObject = $objectData->get();

        // Assert
        self::assertSame($arrayValue, $gotArray);
        self::assertEquals($objectValue, $gotObject);
    }

    /**
     * Ensure JSON encoding is correct and decodes back to the original structure.
     */
    public function test_json_encoding_round_trip(): void
    {
        // Arrange
        $payload = (object) ['x' => 1, 'y' => [2, 3], 'z' => 'ok'];
        $data    = new Data($payload);

        // Act
        $json    = $data->toJson();
        $decoded = json_decode($json);

        // Assert
        self::assertJson($json);
        self::assertSame(json_encode($payload), $json);
        self::assertEquals($payload, $decoded);
    }
}

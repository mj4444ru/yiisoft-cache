<?php

declare(strict_types=1);

namespace Yiisoft\Cache\Tests {

    class MockHelper
    {
        /**
         * @var int virtual time to be returned by mocked time() function.
         * null means normal time() behavior.
         */
        public static $mock_time;
        /**
         * @var string|false value to be returned by mocked json_encode() function.
         * null means normal json_encode() behavior.
         */
        public static $mock_json_encode;

        public static function resetMocks(): void
        {
            static::$mock_time = null;
            static::$mock_json_encode = null;
        }
    }

    /**
     * Mock for the time() function
     * @return int
     */
    function time(): int
    {
        return MockHelper::$mock_time ?? \time();
    }

    /**
     * Mock for the json_encode() function
     * @return string|false
     */
    function json_encode($value, $options = 0, $depth = 512)
    {
        return MockHelper::$mock_json_encode ?? \json_encode($value, $options, $depth);
    }
}

namespace Yiisoft\Cache {
    function time(): int
    {
        return \Yiisoft\Cache\Tests\time();
    }

    function json_encode($value, $options = 0, $depth = 512)
    {
        return \Yiisoft\Cache\Tests\json_encode($value, $options, $depth);
    }
}

namespace Yiisoft\Cache\Dependency {
    function json_encode($value, $options = 0, $depth = 512)
    {
        return \Yiisoft\Cache\Tests\json_encode($value, $options, $depth);
    }
}

<?php
namespace Yiisoft\Cache;

use Yiisoft\Cache\Dependencies\Dependency;
use Yiisoft\Strings\StringHelper;
use yii\helpers\Yii;

/**
 * Cache provides support for the data caching, including cache key composition and dependencies.
 * The actual data caching is performed via [[handler]], which should be configured to be [[\Psr\SimpleCache\CacheInterface]]
 * instance.
 *
 * Application configuration example:
 *
 * ```php
 * return [
 *     'components' => [
 *         'cache' => [
 *             '__class' => Yiisoft\Cache\Cache::class,
 *             'handler' => [
 *                 '__class' => Yiisoft\Cache\FileCache::class,
 *                 'cachePath' => '@runtime/cache',
 *             ],
 *         ],
 *         // ...
 *     ],
 *     // ...
 * ];
 * ```
 *
 * A data item can be stored in the cache by calling [[set()]] and be retrieved back
 * later (in the same or different request) by [[get()]]. In both operations,
 * a key identifying the data item is required. An expiration time and/or a [[Dependency|dependency]]
 * can also be specified when calling [[set()]]. If the data item expires or the dependency
 * changes at the time of calling [[get()]], the cache will return no data.
 *
 * A typical usage pattern of cache is like the following:
 *
 * ```php
 * $key = 'demo';
 * $data = $cache->get($key);
 * if ($data === null) {
 *     // ...generate $data here...
 *     $cache->set($key, $data, $duration, $dependency);
 * }
 * ```
 *
 * Because Cache implements the [[\ArrayAccess]] interface, it can be used like an array. For example,
 *
 * ```php
 * $cache['foo'] = 'some data';
 * echo $cache['foo'];
 * ```
 *
 * For more details and usage information on Cache, see the [guide article on caching](guide:caching-overview)
 * and [PSR-16 specification](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-16-simple-cache.md).
 */
class Cache implements CacheInterface
{
    /**
     * @var \Psr\SimpleCache\CacheInterface actual cache handler.
     */
    private $handler;


    /**
     * @param \Psr\SimpleCache\CacheInterface cache handler.
     */
    public function __construct(\Psr\SimpleCache\CacheInterface $handler = null)
    {
        $this->setHandler($handler);
    }

    public function getHandler(): \Psr\SimpleCache\CacheInterface
    {
        return $this->handler;
    }

    /**
     * @param \Psr\SimpleCache\CacheInterface|array cache handler.
     */
    public function setHandler(\Psr\SimpleCache\CacheInterface $handler = null): self
    {
        if ($handler) {
            $this->handler = $handler;
        }

        return $this;
    }

    /**
     * Builds a normalized cache key from a given key.
     *
     * If the given key is a string containing alphanumeric characters only and no more than 32 characters,
     * then the key will be returned back as it is. Otherwise, a normalized key is generated by serializing
     * the given key and applying MD5 hashing.
     *
     * @param mixed $key the key to be normalized
     * @return string the generated cache key
     */
    protected function buildKey($key)
    {
        if (is_string($key)) {
            return ctype_alnum($key) && StringHelper::byteLength($key) <= 32 ? $key : md5($key);
        }
        return md5(json_encode($key));
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, $default = null)
    {
        $key = $this->buildKey($key);
        $value = $this->handler->get($key);

        if ($value === null) {
            return $default;
        }

        if (is_array($value) && isset($value[1]) && $value[1] instanceof Dependency) {
            if ($value[1]->isChanged($this)) {
                return $default;
            }
            return $value[0];
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function has($key)
    {
        $key = $this->buildKey($key);
        return $this->handler->has($key);
    }

    /**
     * Retrieves multiple values from cache with the specified keys.
     * Some caches (such as memcache, apc) allow retrieving multiple cached values at the same time,
     * which may improve the performance. In case a cache does not support this feature natively,
     * this method will try to simulate it.
     * @param string[] $keys list of string keys identifying the cached values
     * @param mixed $default Default value to return for keys that do not exist.
     * @return array list of cached values corresponding to the specified keys. The array
     * is returned in terms of (key, value) pairs.
     * If a value is not cached or expired, the corresponding array value will be false.
     */
    public function getMultiple($keys, $default = null)
    {
        $keyMap = [];
        foreach ($keys as $key) {
            $keyMap[$key] = $this->buildKey($key);
        }
        $values = $this->handler->getMultiple(array_values($keyMap));
        $results = [];
        foreach ($keyMap as $key => $newKey) {
            $results[$key] = $default;
            if (isset($values[$newKey])) {
                $value = $values[$newKey];
                if (is_array($value) && isset($value[1]) && $value[1] instanceof Dependency) {
                    if ($value[1]->isChanged($this)) {
                        continue;
                    }

                    $value = $value[0];
                }
                $results[$key] = $value;
            }
        }

        return $results;
    }

    /**
     * Stores a value identified by a key into cache.
     * If the cache already contains such a key, the existing value and
     * expiration time will be replaced with the new ones, respectively.
     *
     * @param mixed $key a key identifying the value to be cached. This can be a simple string or
     * a complex data structure consisting of factors representing the key.
     * @param mixed $value the value to be cached
     * @param null|int|\DateInterval $ttl the TTL value of this item. If not set, default value is used.
     * @param Dependency $dependency dependency of the cached item. If the dependency changes,
     * the corresponding value in the cache will be invalidated when it is fetched via [[get()]].
     * This parameter is ignored if [[serializer]] is false.
     * @return bool whether the value is successfully stored into cache
     */
    public function set($key, $value, $ttl = null, $dependency = null): bool
    {
        if ($dependency !== null) {
            $dependency->evaluateDependency($this);
            $value = [$value, $dependency];
        }
        $key = $this->buildKey($key);
        return $this->handler->set($key, $value, $ttl);
    }

    /**
     * Stores multiple items in cache. Each item contains a value identified by a key.
     * If the cache already contains such a key, the existing value and
     * expiration time will be replaced with the new ones, respectively.
     *
     * @param array $items the items to be cached, as key-value pairs.
     * @param null|int|\DateInterval $ttl the TTL value of this item. If not set, default value is used.
     * @param Dependency $dependency dependency of the cached items. If the dependency changes,
     * the corresponding values in the cache will be invalidated when it is fetched via [[get()]].
     * This parameter is ignored if [[serializer]] is false.
     * @return array array of failed keys
     */
    public function setMultiple($items, $ttl = 0, $dependency = null): bool
    {
        if ($dependency !== null) {
            $dependency->evaluateDependency($this);
        }

        $data = [];
        foreach ($items as $key => $value) {
            if ($dependency !== null) {
                $value = [$value, $dependency];
            }
            $key = $this->buildKey($key);
            $data[$key] = $value;
        }

        return $this->handler->setMultiple($data, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple($keys): bool
    {
        $actualKeys = [];
        foreach ($keys as $key) {
            $actualKeys[] = $this->buildKey($key);
        }
        return $this->handler->deleteMultiple($actualKeys);
    }

    /**
     * Stores multiple items in cache. Each item contains a value identified by a key.
     * If the cache already contains such a key, the existing value and expiration time will be preserved.
     *
     * @param array $values the items to be cached, as key-value pairs.
     * @param null|int|\DateInterval $ttl the TTL value of this item. If not set, default value is used.
     * @param Dependency $dependency dependency of the cached items. If the dependency changes,
     * the corresponding values in the cache will be invalidated when it is fetched via [[get()]].
     * This parameter is ignored if [[serializer]] is false.
     * @return bool
     */
    public function addMultiple($values, $ttl = 0, $dependency = null): bool
    {
        if ($dependency !== null) {
            $dependency->evaluateDependency($this);
        }

        $data = [];
        foreach ($values as $key => $value) {
            if ($dependency !== null) {
                $value = [$value, $dependency];
            }

            $key = $this->buildKey($key);
            $data[$key] = $value;
        }

        $existingValues = $this->handler->getMultiple(array_keys($data));
        foreach ($existingValues as $key => $value) {
            if ($value !== null) {
                unset($data[$key]);
            }
        }
        return $this->handler->setMultiple($data, $ttl);
    }

    /**
     * Stores a value identified by a key into cache if the cache does not contain this key.
     * Nothing will be done if the cache already contains the key.
     * @param mixed $key a key identifying the value to be cached. This can be a simple string or
     * a complex data structure consisting of factors representing the key.
     * @param mixed $value the value to be cached
     * @param null|int|\DateInterval $ttl the TTL value of this item. If not set, default value is used.
     * @param Dependency $dependency dependency of the cached item. If the dependency changes,
     * the corresponding value in the cache will be invalidated when it is fetched via [[get()]].
     * This parameter is ignored if [[serializer]] is false.
     * @return bool whether the value is successfully stored into cache
     */
    public function add($key, $value, $ttl = null, $dependency = null): bool
    {
        if ($dependency !== null) {
            $dependency->evaluateDependency($this);
            $value = [$value, $dependency];
        }

        $key = $this->buildKey($key);

        if ($this->handler->has($key)) {
            return false;
        }

        return $this->handler->set($key, $value, $ttl);
    }

    /**
     * Deletes a value with the specified key from cache.
     * @param mixed $key a key identifying the value to be deleted from cache. This can be a simple string or
     * a complex data structure consisting of factors representing the key.
     * @return bool if no error happens during deletion
     */
    public function delete($key): bool
    {
        $key = $this->buildKey($key);

        return $this->handler->delete($key);
    }

    /**
     * Deletes all values from cache.
     * Be careful of performing this operation if the cache is shared among multiple applications.
     * @return bool whether the flush operation was successful.
     */
    public function clear(): bool
    {
        return $this->handler->clear();
    }

    /**
     * Returns whether there is a cache entry with a specified key.
     * This method is required by the interface [[\ArrayAccess]].
     * @param string $key a key identifying the cached value
     * @return bool
     */
    public function offsetExists($key): bool
    {
        return $this->get($key) !== false;
    }

    /**
     * Retrieves the value from cache with a specified key.
     * This method is required by the interface [[\ArrayAccess]].
     * @param string $key a key identifying the cached value
     * @return mixed the value stored in cache, false if the value is not in the cache or expired.
     */
    public function offsetGet($key)
    {
        return $this->get($key);
    }

    /**
     * Stores the value identified by a key into cache.
     * If the cache already contains such a key, the existing value will be
     * replaced with the new ones. To add expiration and dependencies, use the [[set()]] method.
     * This method is required by the interface [[\ArrayAccess]].
     * @param string $key the key identifying the value to be cached
     * @param mixed $value the value to be cached
     */
    public function offsetSet($key, $value): void
    {
        $this->set($key, $value);
    }

    /**
     * Deletes the value with the specified key from cache
     * This method is required by the interface [[\ArrayAccess]].
     * @param string $key the key of the value to be deleted
     */
    public function offsetUnset($key): void
    {
        $this->delete($key);
    }

    /**
     * Method combines both [[set()]] and [[get()]] methods to retrieve value identified by a $key,
     * or to store the result of $callable execution if there is no cache available for the $key.
     *
     * Usage example:
     *
     * ```php
     * public function getTopProducts($count = 10) {
     *     $cache = $this->cache; // Could be Yii::getApp()->cache
     *     return $cache->getOrSet(['top-n-products', 'n' => $count], function ($cache) use ($count) {
     *         return Products::find()->mostPopular()->limit(10)->all();
     *     }, 1000);
     * }
     * ```
     *
     * @param mixed $key a key identifying the value to be cached. This can be a simple string or
     * a complex data structure consisting of factors representing the key.
     * @param callable|\Closure $callable the callable or closure that will be used to generate a value to be cached.
     * In case $callable returns `false`, the value will not be cached.
     * @param null|int|\DateInterval $ttl the TTL value of this item. If not set, default value is used.
     * @param Dependency $dependency dependency of the cached item. If the dependency changes,
     * the corresponding value in the cache will be invalidated when it is fetched via [[get()]].
     * This parameter is ignored if [[serializer]] is `false`.
     * @return mixed result of $callable execution
     */
    public function getOrSet($key, $callable, $ttl = null, $dependency = null)
    {
        if (($value = $this->get($key)) !== null) {
            return $value;
        }

        $value = $callable($this);
        if (!$this->set($key, $value, $ttl, $dependency)) {
            Yii::warning('Failed to set cache value for key ' . json_encode($key), __METHOD__);
        }

        return $value;
    }
}

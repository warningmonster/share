<?php

/*
 * PHP script for downloading videos from youtube
 * Copyright (C) 2012-2018  John Eckman
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, see <http://www.gnu.org/licenses/>.
 */

namespace YoutubeDownloader\Cache;

use DateTimeInterface;

/**
 * Describes the interface of a container that exposes methods to read its entries.
 *
 * This interface must be compatible with PSR-16 Psr\SimpleCache\CacheInterface
 */

class FileCache implements Cache
{
    /**
     * Create the cache with a directory
     *
     * @param string            $directory The cache root directory
     * @param array             $config    some configuration
     * @param CacheException If $directory not exists
     * @param CacheException If $directory is not writable
     *
     * @return FileCache
     */
    public static function createFromDirectory($directory, array $config = [])
    {
        $directory = rtrim(strval($directory), '/\\');

        if (! file_exists($directory)) {
            throw new CacheException(
                sprintf('cache directory "%s" does not exist.', $directory)
            );
        }

        if (! is_dir($directory)) {
            throw new CacheException(
                sprintf('cache directory "%s" is not a directory.', $directory)
            );
        }

        if (! is_readable($directory)) {
            throw new CacheException(
                sprintf('cache directory "%s" is not readable.', $directory)
            );
        }

        if (! is_writable($directory)) {
            throw new CacheException(
                sprintf('cache directory "%s" is not writable.', $directory)
            );
        }

        return new self($directory, $config);
    }

    /**
     * @var string
     */
    private $root;

    /**
     * @var array
     */
    private $config = [
        'writeFlags' => LOCK_EX,
    ];

    /**
     * Fetches a value from the cache.
     *
     * @param string $directory The cache root directory
     * @param array  $config    some configuration
     */
    public function __construct($directory, array $config = [])
    {
        if (array_key_exists('writeFlags', $config)) {
            $this->config['writeFlags'] = $config['writeFlags'];
        }

        $this->root = rtrim(strval($directory), '/\\');
    }

    /**
     * Fetches a value from the cache.
     *
     * @param string $key     the unique key of this item in the cache
     * @param mixed  $default default value to return if the key does not exist
     *
     * @throws invalidArgumentException
     *                                  MUST be thrown if the $key string is not a legal value
     *
     * @return mixed the value of the item from the cache, or $default in case of cache miss
     */
    public function get($key, $default = null)
    {
        $this->validateKey($key);

        $location = $this->root . \DIRECTORY_SEPARATOR . $key;

        $data = false;

        if (file_exists($location)) {
            $data = file_get_contents($location);
        }

        if ($data === false) {
            return $default;
        }

        $data = @unserialize($data);

        if ($data === false) {
            return $default;
        }

        $expirationTimestamp = $data[1] ?: null;

        if ($expirationTimestamp !== null && time() > $expirationTimestamp) {
            $this->delete($key);

            return $default;
        }

        return $data[0];
    }

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string                $key   the key of the item to store
     * @param mixed                 $value the value of the item to store, must be serializable
     * @param null|int|DateInterval $ttl   Optional. The TTL value of this item. If no value is sent and
     *                                     the driver supports TTL then the library may set a default value
     *                                     for it or let the driver take care of that.
     *
     * @throws invalidArgumentException
     *                                  MUST be thrown if the $key string is not a legal value
     *
     * @return bool true on success and false on failure
     */
    public function set($key, $value, $ttl = null)
    {
        $this->validateKey($key);

        if ($ttl instanceof DateTimeInterface) {
            $expirationTimestamp = $ttl->getTimestamp();
        } elseif (is_int($ttl)) {
            $expirationTimestamp = time() + $ttl;
        } elseif (null === $ttl) {
            $expirationTimestamp = $ttl;
        } else {
            throw new InvalidArgumentException('$ttl must be of type null, integer or \DateTimeInterface.');
        }

        $data = serialize([
            $value,
            $expirationTimestamp,
        ]);

        $location = $this->root . \DIRECTORY_SEPARATOR . $key;

        $size = file_put_contents($location, $data, $this->config['writeFlags']);

        if ($size === false) {
            return false;
        }

        return true;
    }

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key the unique cache key of the item to delete
     *
     * @throws invalidArgumentException
     *                                  MUST be thrown if the $key string is not a legal value
     *
     * @return bool True if the item was successfully removed. False if there was an error.
     */
    public function delete($key)
    {
        $this->validateKey($key);

        $location = $this->root . \DIRECTORY_SEPARATOR . $key;

        return unlink($location);
    }

    /**
     * @param string $key
     *
     * @throws InvalidArgumentException
     */
    private function validateKey($key)
    {
        if (! is_string($key)) {
            throw new InvalidArgumentException(
                sprintf('Cache key must be string, "%s" given', gettype($key))
            );
        }

        if (! isset($key[0])) {
            throw new InvalidArgumentException(
                'Cache key cannot be an empty string'
            );
        }

        if (preg_match('~[^a-zA-Z0-9_\\-$]+~', $key) !== 0) {
            throw new InvalidArgumentException(sprintf(
                'Invalid key: "%s". The key contains one or more not allowed characters, allowed are only %s',
                $key,
                '`a-z`, `A-Z`, `0-9`, `_` and `-`'
            ));
        }
    }
}

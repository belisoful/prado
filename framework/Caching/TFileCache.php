<?php

/**
 * TFileCache class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Caching;

use Prado\Prado;
use Prado\Exceptions\TConfigurationException;

/**
 * TFileCache class.
 *
 * TFileCache implements a file-based {@see TCache} application module. Each
 * cache entry is stored as a single file under the configured
 * {@see getDirectory Directory}, named by a SHA-1 hash of the internal key.
 * The file contains a serialized array with the absolute expiry timestamp and
 * the serialized payload.
 *
 * TFileCache requires no external extensions — it works on any PHP host with a
 * writable filesystem. For high-throughput deployments, prefer a shared-memory
 * cache such as {@see \Prado\Caching\TAPCCache},
 * {@see \Prado\Caching\TMemCache}, or {@see \Prado\Caching\TRedisCache}.
 *
 * **Concurrency**: writes use a `tempnam()` + atomic `rename()` so that
 * concurrent readers never see a partially-written entry.
 *
 * **TTL semantics**: a `$expire` of `0` passed to {@see TCache::set} or
 * {@see TCache::add} falls back to the {@see getDefaultTtl DefaultTtl}
 * property. If `DefaultTtl` is also `0` the entry never expires.
 *
 * **Cache dependencies** ({@see ICacheDependency}) are honored: the dependency
 * is serialized alongside the value and re-validated on every {@see get} by
 * the {@see TCache} base class.
 *
 * Configure in application.xml:
 * ```xml
 * <module id="cache" class="Prado\Caching\TFileCache"
 *         Directory="Application.runtime.cache" />
 * ```
 *
 * Or instantiate directly:
 * ```php
 * $cache = new TFileCache('/tmp/my-cache', 3600);
 * $cache->init(null);
 * $cache->set('key', $value);
 * $value = $cache->get('key');
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 4.4.0
 */
class TFileCache extends TCache
{
	/** Default filename prefix for the atomic temporary write files. */
	public const CACHE_FILE_PREFIX = '.prado-cache-';

	/** Payload array key for the cached value. */
	protected const CACHE_VALUE = 'value';

	/** Payload array key for the absolute expiry timestamp (Unix seconds; 0 = never expires). */
	protected const CACHE_EXPIRED = 'expired';

	/** @var string Absolute path to the cache directory; empty until configured. */
	private string $_dir = '';

	/** @var int Default TTL in seconds; 0 means never expire. */
	private int $_defaultTtl = 0;

	/** @var string Filename prefix used when creating atomic temporary write files. */
	private string $_tempFilePrefix = '';

	/**
	 * @param string $directory the cache directory; created if it does not exist.
	 *   Pass an empty string (default) to use the application runtime path.
	 * @param int $defaultTtl the default TTL in seconds (0 = never expire)
	 */
	public function __construct(string $directory = '', int $defaultTtl = 0)
	{
		parent::__construct();
		if ($directory !== '') {
			$this->setDirectory($directory);
		}
		$this->setDefaultTtl($defaultTtl);
		$this->setTempFilePrefix(static::CACHE_FILE_PREFIX);
	}

	/**
	 * Initializes the cache module. When no {@see getDirectory Directory} has been
	 * set, a `filecache/` subdirectory under the application runtime path is used
	 * and created if necessary. Throws when the directory is not writable.
	 *
	 * @param null|\Prado\Xml\TXmlElement $config module configuration
	 * @throws TConfigurationException when the directory cannot be created or is
	 *   not writable
	 */
	public function init($config)
	{
		$directory = $this->getDirectory();
		if ($directory === '') {
			$directory = $this->getApplication()->getRuntimePath() . DIRECTORY_SEPARATOR . 'filecache';
			$this->setDirectory($directory);
			$directory = $this->getDirectory();
		}
		if (!is_writable($directory)) {
			throw new TConfigurationException('filecache_directory_not_writable', $directory);
		}
		parent::init($config);
	}

	// ---------------------------------------------------------------- accessors

	/**
	 * @return string the absolute path to the cache directory
	 */
	protected function getDirectoryDirect(): string
	{
		return $this->_dir;
	}

	/**
	 * @param string $value the absolute resolved directory path to store directly
	 */
	protected function setDirectoryDirect(string $value): void
	{
		$this->_dir = $value;
	}

	/**
	 * @return string the absolute path to the cache directory
	 */
	public function getDirectory(): string
	{
		return $this->getDirectoryDirect();
	}

	/**
	 * Sets the cache directory, creating it (recursively) when it does not exist.
	 *
	 * @param string $value the directory path; namespace-style paths
	 *   (e.g. `Application.runtime.cache`) are resolved via
	 *   {@see \Prado\Prado::getPathOfNamespace()}
	 * @throws TConfigurationException when the value is empty, or when the
	 *   directory does not exist and cannot be created
	 */
	public function setDirectory(string $value): void
	{
		if ($value === '') {
			throw new TConfigurationException('filecache_directory_required');
		}
		// Resolve namespace-style paths (e.g. "Application.runtime.cache").
		if (($path = Prado::getPathOfNamespace($value)) !== null) {
			$value = $path;
		}
		if (!is_dir($value) && !@mkdir($value, 0o755, true) && !is_dir($value)) {
			throw new TConfigurationException('filecache_directory_create_failed', $value);
		}
		$this->setDirectoryDirect(rtrim(realpath($value) ?: $value, '/\\'));
	}

	/**
	 * @return int the default TTL in seconds; 0 means entries never expire
	 */
	protected function getDefaultTtlDirect(): int
	{
		return $this->_defaultTtl;
	}

	/**
	 * @param int $value the default TTL in seconds
	 */
	protected function setDefaultTtlDirect(int $value): void
	{
		$this->_defaultTtl = $value;
	}

	/**
	 * @return int the default TTL in seconds; 0 means entries never expire
	 */
	public function getDefaultTtl(): int
	{
		return $this->getDefaultTtlDirect();
	}

	/**
	 * @param int $value the default TTL in seconds; values below zero are clamped to 0
	 */
	public function setDefaultTtl(int $value): void
	{
		$this->setDefaultTtlDirect(max(0, $value));
	}

	/**
	 * @return string the filename prefix used when creating atomic temporary write files
	 */
	protected function getTempFilePrefixDirect(): string
	{
		return $this->_tempFilePrefix;
	}

	/**
	 * @param string $value the filename prefix to store directly
	 */
	protected function setTempFilePrefixDirect(string $value): void
	{
		$this->_tempFilePrefix = $value;
	}

	/**
	 * @return string the filename prefix used when creating atomic temporary write files
	 */
	public function getTempFilePrefix(): string
	{
		return $this->getTempFilePrefixDirect();
	}

	/**
	 * Sets the filename prefix applied to the atomic temporary files created
	 * during cache writes. The prefix is passed directly to {@see tempnam} and
	 * therefore follows the same length constraints as that function.
	 *
	 * @param string $value the filename prefix (e.g. `.my-cache-`)
	 */
	public function setTempFilePrefix(string $value): void
	{
		$this->setTempFilePrefixDirect($value);
	}

	// ---------------------------------------------------------------- ICache impl

	/**
	 * Deletes all `*.cache` files in the configured directory.
	 *
	 * @return bool true when all files are removed successfully; false when at
	 *   least one file could not be removed
	 */
	public function flush()
	{
		$dir = $this->getDirectory();
		if (!is_dir($dir)) {
			return true;
		}
		$ok = true;
		foreach (glob($dir . DIRECTORY_SEPARATOR . '*.cache') ?: [] as $f) {
			if (!$this->unlink($f)) {
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Retrieves a stored entry by its TCache-generated unique key.
	 *
	 * @param string $key the unique key
	 * @return false|mixed the stored value, or false if the entry is missing,
	 *   malformed, or expired
	 */
	protected function getValue($key)
	{
		$file = $this->pathFor($key);
		if (!$this->isFile($file)) {
			return false;
		}
		$raw = $this->getContents($file);
		if ($raw === false || $raw === '') {
			return false;
		}
		$decoded = $this->unserialize($raw);
		if (!is_array($decoded) || !array_key_exists(static::CACHE_EXPIRED, $decoded) || !array_key_exists(static::CACHE_VALUE, $decoded)) {
			return false;
		}
		$expire = (int) $decoded[static::CACHE_EXPIRED];
		if ($expire > 0 && $expire <= $this->now()) {
			$this->unlink($file);
			return false;
		}
		return $decoded[static::CACHE_VALUE];
	}

	/**
	 * Writes a cache entry atomically using a temp file + rename.
	 *
	 * @param string $key the unique key
	 * @param mixed $value the value to store
	 * @param int $expire TTL in seconds; 0 falls back to {@see getDefaultTtl}
	 * @param bool $exclusive when true, aborts if the final file already exists
	 *   (used by {@see addValue} to prevent overwriting a live entry)
	 * @return bool true on success
	 */
	protected function writeEntry(string $key, mixed $value, int $expire, bool $exclusive): bool
	{
		$ttl = $expire > 0 ? $expire : $this->getDefaultTtl();
		$entry = [
			static::CACHE_VALUE => $value,
			static::CACHE_EXPIRED => $ttl > 0 ? $this->now() + $ttl : 0,
		];
		$file = $this->pathFor($key);
		$tmpFile = $this->tempnam($this->getDirectory(), $this->getTempFilePrefix());
		if ($tmpFile === false) {
			return false;
		}
		if ($this->putContents($tmpFile, $this->serialize($entry)) === false) {
			$this->unlink($tmpFile);
			return false;
		}
		$this->chmod($tmpFile, 0o644);
		if ($exclusive && $this->isFile($file)) {
			$this->unlink($tmpFile);
			return false;
		}
		if (!$this->rename($tmpFile, $file)) {
			$this->unlink($tmpFile);
			return false;
		}
		return true;
	}

	/**
	 * Stores a value under the given unique key, overwriting any existing entry.
	 *
	 * @param string $key the unique key
	 * @param mixed $value the value to store
	 * @param int $expire TTL in seconds; 0 falls back to {@see getDefaultTtl}
	 * @return bool true on success
	 */
	protected function setValue($key, $value, $expire)
	{
		return $this->writeEntry($key, $value, (int) $expire, false);
	}

	/**
	 * Stores a value only when no live entry already exists under the key.
	 *
	 * @param string $key the unique key
	 * @param mixed $value the value to store
	 * @param int $expire TTL in seconds; 0 falls back to {@see getDefaultTtl}
	 * @return bool true when the entry was stored; false when a live entry
	 *   already existed
	 */
	protected function addValue($key, $value, $expire)
	{
		$file = $this->pathFor($key);
		if ($this->isFile($file) && $this->getValue($key) !== false) {
			return false;
		}
		return $this->writeEntry($key, $value, (int) $expire, true);
	}

	/**
	 * Deletes an entry by its unique key.
	 *
	 * @param string $key the unique key
	 * @return bool true on success; also true when the entry was not present
	 */
	protected function deleteValue($key)
	{
		$file = $this->pathFor($key);
		if ($this->isFile($file)) {
			return $this->unlink($file);
		}
		return true;
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * Returns the absolute filesystem path for a given cache key.
	 *
	 * @param string $key the unique key
	 * @return string the absolute path to the cache file
	 */
	protected function pathFor(string $key): string
	{
		return $this->getDirectory() . DIRECTORY_SEPARATOR . $this->hashKeyToken($key) . '.cache';
	}

	// ------------------------------------------------------------------ encapsulation

	/**
	 * Returns the SHA-1 hex digest of a cache key token, used as the cache file name.
	 *
	 * @param string $token the unique key token
	 * @return string 40-character lowercase hex SHA-1 hash
	 */
	protected function hashKeyToken(string $token): string
	{
		return sha1($token);
	}

	/**
	 * Returns the current Unix timestamp. Extracted to allow subclasses and
	 * test doubles to control clock behavior without modifying real system time.
	 *
	 * @return int the current Unix timestamp in seconds
	 */
	protected function now(): int
	{
		return time();
	}

	/**
	 * Returns whether the given path refers to an existing regular file.
	 * Extracted to allow subclasses and test doubles to intercept filesystem
	 * existence checks.
	 *
	 * @param string $path the filesystem path to test
	 * @return bool true when the path exists and is a regular file
	 */
	protected function isFile(string $path): bool
	{
		return is_file($path);
	}

	/**
	 * Creates a uniquely named temporary file in the given directory and returns
	 * its path. Returns false when the file cannot be created.
	 *
	 * @param string $dir the directory in which to create the temporary file
	 * @param string $prefix the filename prefix for the temporary file
	 * @return false|string the path of the created temporary file, or false on failure
	 */
	protected function tempnam(string $dir, string $prefix): string|false
	{
		return @tempnam($dir, $prefix);
	}

	/**
	 * Serializes a value to a string for storage in a cache file.
	 *
	 * @param mixed $value the value to serialize
	 * @return string the serialized representation
	 */
	protected function serialize(mixed $value): string
	{
		return serialize($value);
	}

	/**
	 * Unserializes a string produced by {@see serialize}.
	 * Returns false when the string is not valid serialized data.
	 *
	 * @param string $value the serialized string to decode
	 * @return mixed the unserialized value, or false on failure
	 */
	protected function unserialize(string $value): mixed
	{
		return @unserialize($value);
	}

	/**
	 * Reads and returns the entire contents of a file.
	 * Returns false when the file cannot be read.
	 *
	 * @param string $filePath the path of the file to read
	 * @return false|string the file contents, or false on failure
	 */
	protected function getContents(string $filePath): string|false
	{
		return @file_get_contents($filePath);
	}

	/**
	 * Writes data to a file, replacing its current contents.
	 * Returns false when the file cannot be written.
	 *
	 * @param string $filePath the path of the file to write
	 * @param string $data the data to write
	 * @return false|int the number of bytes written, or false on failure
	 */
	protected function putContents(string $filePath, string $data): int|false
	{
		return @file_put_contents($filePath, $data);
	}

	/**
	 * Deletes a file from the filesystem.
	 *
	 * @param string $filePath the path of the file to delete
	 * @return bool true on success, false on failure
	 */
	protected function unlink(string $filePath): bool
	{
		return @unlink($filePath);
	}

	/**
	 * Renames (moves) a file from one path to another.
	 *
	 * @param string $srcFilePath the source path
	 * @param string $destFilePath the destination path
	 * @return bool true on success, false on failure
	 */
	protected function rename(string $srcFilePath, string $destFilePath): bool
	{
		return @rename($srcFilePath, $destFilePath);
	}

	/**
	 * Sets the permissions on a file.
	 *
	 * @param string $filePath the path of the file
	 * @param int $mode the permissions bitmask (e.g. `0o644`)
	 * @return bool true on success, false on failure
	 */
	protected function chmod(string $filePath, int $mode): bool
	{
		return @chmod($filePath, $mode);
	}
}

<?php

/**
 * TFileCacheTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

use Prado\Caching\TFileCache;
use Prado\Exceptions\TConfigurationException;
use Prado\TApplication;

// ── Helper class ───────────────────────────────────────────────────────────────

/**
 * Exposes protected internals and overrides now() for clock-controlled TTL tests.
 * Using this subclass eliminates all sleep() calls from the test suite, making
 * expiry tests instant and deterministic.
 */
class TFileCacheTestAccessor extends TFileCache
{
	/** @var int|null when set, now() returns this value instead of time() */
	public ?int $fakeNow = null;

	protected function now(): int
	{
		return $this->fakeNow ?? parent::now();
	}

	public function pubNow(): int
	{
		return $this->now();
	}

	public function pubHashKeyToken(string $token): string
	{
		return $this->hashKeyToken($token);
	}

	public function pubPathFor(string $key): string
	{
		return $this->pathFor($key);
	}

	public function pubSerialize(mixed $value): string
	{
		return $this->serialize($value);
	}

	public function pubUnserialize(string $value): mixed
	{
		return $this->unserialize($value);
	}

	public function pubGetContents(string $filePath): string|false
	{
		return $this->getContents($filePath);
	}

	public function pubPutContents(string $filePath, string $data): int|false
	{
		return $this->putContents($filePath, $data);
	}

	public function pubUnlink(string $filePath): bool
	{
		return $this->unlink($filePath);
	}

	public function pubRename(string $srcFilePath, string $destFilePath): bool
	{
		return $this->rename($srcFilePath, $destFilePath);
	}

	public function pubChmod(string $filePath, int $mode): bool
	{
		return $this->chmod($filePath, $mode);
	}

	public function pubTempnam(string $dir, string $prefix): string|false
	{
		return $this->tempnam($dir, $prefix);
	}

	public function pubGetTempFilePrefixDirect(): string
	{
		return $this->getTempFilePrefixDirect();
	}

	public function pubIsFile(string $path): bool
	{
		return $this->isFile($path);
	}
}

// ── Test class ─────────────────────────────────────────────────────────────────

/**
 * TFileCacheTest class.
 *
 * Comprehensive unit tests for TFileCache: directory configuration, init(),
 * set/get/add/delete/flush, TTL semantics (clock-controlled, no sleep()),
 * atomic writes, corrupt-file handling, ArrayAccess, DefaultTtl fallback,
 * protected helper methods, and edge cases.
 *
 * @package Prado\Tests\Unit\Caching
 */
class TFileCacheTest extends PHPUnit\Framework\TestCase
{
	private static string $cacheDir;

	/** @var TFileCacheTestAccessor */
	private TFileCacheTestAccessor $cache;

	private ?TApplication $app = null;

	public static function setUpBeforeClass(): void
	{
		self::$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prado_filecache_test_' . getmypid();
		if (!is_dir(self::$cacheDir)) {
			mkdir(self::$cacheDir, 0o755, true);
		}
	}

	public static function tearDownAfterClass(): void
	{
		// Remove all test cache files.
		if (is_dir(self::$cacheDir)) {
			foreach (glob(self::$cacheDir . '/*') ?: [] as $f) {
				@unlink($f);
			}
			@rmdir(self::$cacheDir);
		}
	}

	protected function setUp(): void
	{
		$basePath = __DIR__ . '/mockapp';
		$this->app = new TApplication($basePath);

		$this->cache = new TFileCacheTestAccessor(self::$cacheDir);
		$this->cache->setPrimaryCache(false);
		$this->cache->init(null);
	}

	protected function tearDown(): void
	{
		// Flush between tests to prevent cross-test interference.
		$this->cache->flush();
		$this->app = null;
	}

	// ── Construction ─────────────────────────────────────────────────────────

	public function testIsInstanceOfTFileCache(): void
	{
		$this->assertInstanceOf(TFileCache::class, $this->cache);
	}

	public function testConstructWithNoArgsCreatesInstance(): void
	{
		$cache = new TFileCache();
		$this->assertInstanceOf(TFileCache::class, $cache);
		$this->assertSame('', $cache->getDirectory());
		$this->assertSame(0, $cache->getDefaultTtl());
	}

	public function testConstructWithDirectorySetsDirectory(): void
	{
		$this->assertSame(realpath(self::$cacheDir) ?: self::$cacheDir, $this->cache->getDirectory());
	}

	public function testConstructWithDefaultTtlSetsDefaultTtl(): void
	{
		$cache = new TFileCacheTestAccessor(self::$cacheDir, 300);
		$this->assertSame(300, $cache->getDefaultTtl());
	}

	// ── Directory property ────────────────────────────────────────────────────

	public function testGetSetDirectory(): void
	{
		$newDir = self::$cacheDir . DIRECTORY_SEPARATOR . 'subdir';
		$this->cache->setDirectory($newDir);
		$this->assertSame(realpath($newDir) ?: $newDir, $this->cache->getDirectory());
		// Directory should have been created.
		$this->assertDirectoryExists($newDir);
		@rmdir($newDir);
	}

	public function testSetDirectoryCreatesDirectoryIfNotExists(): void
	{
		$newDir = self::$cacheDir . DIRECTORY_SEPARATOR . 'autocreated_' . uniqid();
		$this->assertDirectoryDoesNotExist($newDir);

		$this->cache->setDirectory($newDir);

		$this->assertDirectoryExists($newDir);
		@rmdir($newDir);
	}

	public function testSetDirectoryCreatesNestedDirectoriesRecursively(): void
	{
		$newDir = self::$cacheDir . DIRECTORY_SEPARATOR . 'level1' . DIRECTORY_SEPARATOR . 'level2';
		$this->assertDirectoryDoesNotExist($newDir);

		$this->cache->setDirectory($newDir);

		$this->assertDirectoryExists($newDir);
		@rmdir($newDir);
		@rmdir(self::$cacheDir . DIRECTORY_SEPARATOR . 'level1');
	}

	public function testSetDirectoryThrowsOnEmptyString(): void
	{
		$this->expectException(TConfigurationException::class);
		$this->cache->setDirectory('');
	}

	// ── DefaultTtl property ───────────────────────────────────────────────────

	public function testGetSetDefaultTtl(): void
	{
		$this->cache->setDefaultTtl(600);
		$this->assertSame(600, $this->cache->getDefaultTtl());
	}

	public function testSetDefaultTtlClampsNegativeToZero(): void
	{
		$this->cache->setDefaultTtl(-100);
		$this->assertSame(0, $this->cache->getDefaultTtl());
	}

	public function testSetDefaultTtlZeroAllowed(): void
	{
		$this->cache->setDefaultTtl(0);
		$this->assertSame(0, $this->cache->getDefaultTtl());
	}

	// ── TempFilePrefix property ───────────────────────────────────────────────

	public function testDefaultTempFilePrefixMatchesCacheFilePrefixConstant(): void
	{
		$cache = new TFileCacheTestAccessor();
		// The constructor seeds TempFilePrefix from static::CACHE_FILE_PREFIX.
		$this->assertSame(TFileCache::CACHE_FILE_PREFIX, $cache->getTempFilePrefix());
	}

	public function testDefaultTempFilePrefixIsSetViaDirectAccessor(): void
	{
		$cache = new TFileCacheTestAccessor();
		$this->assertSame(TFileCache::CACHE_FILE_PREFIX, $cache->pubGetTempFilePrefixDirect());
	}

	public function testGetSetTempFilePrefix(): void
	{
		$this->cache->setTempFilePrefix('.my-cache-');
		$this->assertSame('.my-cache-', $this->cache->getTempFilePrefix());
	}

	public function testTempFilePrefixUsedForTempFilesOnWrite(): void
	{
		$this->cache->setTempFilePrefix('.custom-prefix-');
		$this->cache->set('prefix_key', 'val');

		// No leftover temp files with the custom prefix.
		$leftover = glob(self::$cacheDir . DIRECTORY_SEPARATOR . '.custom-prefix-*') ?: [];
		$this->assertCount(0, $leftover, 'No temporary files should remain after a successful write.');
	}

	// ── init() ────────────────────────────────────────────────────────────────

	public function testInitUsesDefaultDirFromRuntimePathWhenNoneSet(): void
	{
		$basePath = __DIR__ . '/mockapp';
		$app = new TApplication($basePath);

		$cache = new TFileCacheTestAccessor();
		$cache->setPrimaryCache(false);
		$cache->init(null);

		$expected = $app->getRuntimePath() . DIRECTORY_SEPARATOR . 'filecache';
		$this->assertSame($expected, $cache->getDirectory());
		$this->assertDirectoryExists($expected);

		// Cleanup.
		$cache->flush();
		@rmdir($expected);
	}

	public function testInitThrowsWhenDirectoryIsNotWritable(): void
	{
		if (!function_exists('posix_getuid') || posix_getuid() === 0) {
			$this->markTestSkipped('Test requires a non-root POSIX environment.');
		}

		$unwritable = self::$cacheDir . DIRECTORY_SEPARATOR . 'unwritable_' . uniqid();
		mkdir($unwritable, 0o000);

		try {
			// setDirectory succeeds because the directory already exists.
			// init() must throw because the directory is not writable.
			$cache = new TFileCacheTestAccessor($unwritable);
			$cache->setPrimaryCache(false);
			$this->expectException(TConfigurationException::class);
			$cache->init(null);
		} finally {
			chmod($unwritable, 0o755);
			@rmdir($unwritable);
		}
	}

	// ── set() / get() ─────────────────────────────────────────────────────────

	public function testSetAndGetString(): void
	{
		$this->cache->set('key1', 'hello');
		$this->assertSame('hello', $this->cache->get('key1'));
	}

	public function testSetAndGetArray(): void
	{
		$data = ['a' => 1, 'b' => [2, 3]];
		$this->cache->set('key_array', $data);
		$this->assertSame($data, $this->cache->get('key_array'));
	}

	public function testSetAndGetObject(): void
	{
		$obj = new stdClass();
		$obj->value = 42;
		$this->cache->set('key_obj', $obj);
		$retrieved = $this->cache->get('key_obj');
		$this->assertInstanceOf(stdClass::class, $retrieved);
		$this->assertSame(42, $retrieved->value);
	}

	public function testGetReturnsFalseForMissingKey(): void
	{
		$this->assertFalse($this->cache->get('nonexistent_' . uniqid()));
	}

	public function testSetOverwritesExistingEntry(): void
	{
		$this->cache->set('overwrite_key', 'first');
		$this->cache->set('overwrite_key', 'second');
		$this->assertSame('second', $this->cache->get('overwrite_key'));
	}

	public function testSetWithEmptyValueAndZeroExpireDeletesEntry(): void
	{
		$this->cache->set('empty_key', 'original');
		// set() with empty value + expire=0 triggers delete path in TCache.
		$this->cache->set('empty_key', '');
		$this->assertFalse($this->cache->get('empty_key'));
	}

	public function testSetWithEmptyValueAndNonZeroExpireStoresIt(): void
	{
		// When expire > 0 and value is empty, set() still stores.
		$result = $this->cache->set('empty_expire_key', '', 3600);
		$this->assertTrue($result);
	}

	// ── TTL / expiry (clock-controlled — no sleep()) ──────────────────────────

	public function testGetReturnsFalseForExpiredEntry(): void
	{
		$this->cache->fakeNow = 1_000_000;
		$this->cache->set('exp_key', 'value', 10); // expires at 1_000_010

		$this->assertSame('value', $this->cache->get('exp_key'));

		$this->cache->fakeNow = 1_000_011; // one second past expiry
		$this->assertFalse($this->cache->get('exp_key'));
	}

	public function testGetWithZeroExpireNeverExpires(): void
	{
		$this->cache->fakeNow = 1_000_000;
		$this->cache->set('persist_key', 'persist', 0);

		$this->cache->fakeNow = 2_000_000; // far in the future
		$this->assertSame('persist', $this->cache->get('persist_key'));
	}

	public function testDefaultTtlAppliedWhenExpireIsZero(): void
	{
		$this->cache->fakeNow = 1_000_000;
		$this->cache->setDefaultTtl(3600);
		$this->cache->set('default_ttl_key', 'val', 0); // expires at 1_003_600

		$this->cache->fakeNow = 1_003_599; // one second before expiry
		$this->assertSame('val', $this->cache->get('default_ttl_key'));

		$this->cache->fakeNow = 1_003_601; // one second past expiry
		$this->assertFalse($this->cache->get('default_ttl_key'));
	}

	public function testExplicitExpireOverridesDefaultTtl(): void
	{
		$this->cache->fakeNow = 1_000_000;
		$this->cache->setDefaultTtl(3600);
		$this->cache->set('explicit_exp', 'val', 10); // expires at 1_000_010, not 1_003_600

		$this->cache->fakeNow = 1_000_009;
		$this->assertSame('val', $this->cache->get('explicit_exp'));

		$this->cache->fakeNow = 1_000_011;
		$this->assertFalse($this->cache->get('explicit_exp'));
	}

	// ── add() ────────────────────────────────────────────────────────────────

	public function testAddStoresValueWhenKeyAbsent(): void
	{
		$result = $this->cache->add('add_key', 'added');
		$this->assertTrue($result);
		$this->assertSame('added', $this->cache->get('add_key'));
	}

	public function testAddReturnsFalseWhenKeyAlreadyExists(): void
	{
		$this->cache->set('dup_key', 'original');
		$result = $this->cache->add('dup_key', 'second');
		$this->assertFalse($result);
		$this->assertSame('original', $this->cache->get('dup_key'));
	}

	public function testAddSucceedsAfterExpiry(): void
	{
		$this->cache->fakeNow = 1_000_000;
		$this->cache->set('add_exp_key', 'first', 10);

		$this->cache->fakeNow = 1_000_011; // past expiry
		$result = $this->cache->add('add_exp_key', 'second');
		$this->assertTrue($result);
		$this->assertSame('second', $this->cache->get('add_exp_key'));
	}

	public function testAddReturnsFalseWhenValueIsEmptyAndExpireZero(): void
	{
		// TCache::add() returns false without delegating when value is empty
		// and expire == 0.
		$result = $this->cache->add('empty_add_key', '');
		$this->assertFalse($result);
	}

	// ── delete() ─────────────────────────────────────────────────────────────

	public function testDeleteRemovesExistingEntry(): void
	{
		$this->cache->set('del_key', 'value');
		$this->cache->delete('del_key');
		$this->assertFalse($this->cache->get('del_key'));
	}

	public function testDeleteReturnsTrueWhenEntryAbsent(): void
	{
		$result = $this->cache->delete('never_set_' . uniqid());
		$this->assertTrue($result);
	}

	// ── flush() ───────────────────────────────────────────────────────────────

	public function testFlushRemovesAllEntries(): void
	{
		$this->cache->set('f1', 'v1');
		$this->cache->set('f2', 'v2');
		$this->cache->set('f3', 'v3');

		$this->cache->flush();

		$this->assertFalse($this->cache->get('f1'));
		$this->assertFalse($this->cache->get('f2'));
		$this->assertFalse($this->cache->get('f3'));
	}

	public function testFlushReturnsTrueOnSuccess(): void
	{
		$this->cache->set('flush_key', 'val');
		$result = $this->cache->flush();
		$this->assertTrue($result);
	}

	public function testFlushOnEmptyDirReturnsTrue(): void
	{
		$emptyDir = self::$cacheDir . DIRECTORY_SEPARATOR . 'empty_' . uniqid();
		mkdir($emptyDir, 0o755, true);

		$cache = new TFileCacheTestAccessor($emptyDir);
		$cache->setPrimaryCache(false);
		$cache->init(null);

		$result = $cache->flush();

		$this->assertTrue($result);
		@rmdir($emptyDir);
	}

	public function testFlushReturnsTrueWhenDirectoryDoesNotExist(): void
	{
		$ghostDir = self::$cacheDir . DIRECTORY_SEPARATOR . 'ghost_' . uniqid();
		mkdir($ghostDir, 0o755, true);

		$cache = new TFileCacheTestAccessor($ghostDir);
		$cache->setPrimaryCache(false);
		$cache->init(null);

		@rmdir($ghostDir); // Remove after init so flush sees a missing dir.

		$result = $cache->flush();
		$this->assertTrue($result);
	}

	// ── Multiple independent keys ─────────────────────────────────────────────

	public function testIndependentKeysDoNotInterfere(): void
	{
		$this->cache->set('k1', 'v1');
		$this->cache->set('k2', 'v2');
		$this->cache->set('k3', 'v3');

		$this->assertSame('v1', $this->cache->get('k1'));
		$this->assertSame('v2', $this->cache->get('k2'));
		$this->assertSame('v3', $this->cache->get('k3'));
	}

	// ── Corrupt / malformed cache files ───────────────────────────────────────

	public function testGetReturnsFalseOnEmptyFile(): void
	{
		$this->cache->set('corrupt_empty', 'val');

		// Locate and truncate the cache file.
		$files = glob(self::$cacheDir . DIRECTORY_SEPARATOR . '*.cache') ?: [];
		foreach ($files as $f) {
			file_put_contents($f, '');
		}

		$this->assertFalse($this->cache->get('corrupt_empty'));
	}

	public function testGetReturnsFalseOnGarbageFile(): void
	{
		$this->cache->set('corrupt_garbage', 'val');

		$files = glob(self::$cacheDir . DIRECTORY_SEPARATOR . '*.cache') ?: [];
		foreach ($files as $f) {
			file_put_contents($f, 'THIS IS NOT VALID SERIALIZED DATA !!!');
		}

		$this->assertFalse($this->cache->get('corrupt_garbage'));
	}

	public function testGetReturnsFalseWhenSerializedArrayMissingKeys(): void
	{
		$this->cache->set('corrupt_keys', 'val');

		// Write a valid serialized array but without the required CACHE_VALUE
		// and CACHE_EXPIRED keys.
		$files = glob(self::$cacheDir . DIRECTORY_SEPARATOR . '*.cache') ?: [];
		foreach ($files as $f) {
			file_put_contents($f, serialize(['x' => 1, 'y' => 2]));
		}

		$this->assertFalse($this->cache->get('corrupt_keys'));
	}

	// ── ArrayAccess interface ─────────────────────────────────────────────────

	public function testOffsetSetAndOffsetGet(): void
	{
		$this->cache['arr_key'] = 'arr_value';
		$this->assertSame('arr_value', $this->cache['arr_key']);
	}

	public function testOffsetExistsTrueWhenPresent(): void
	{
		$this->cache['exists_key'] = 'exists_value';
		$this->assertTrue(isset($this->cache['exists_key']));
	}

	public function testOffsetExistsFalseWhenAbsent(): void
	{
		$this->assertFalse(isset($this->cache['missing_' . uniqid()]));
	}

	public function testOffsetUnsetRemovesEntry(): void
	{
		$this->cache['unset_key'] = 'unset_val';
		unset($this->cache['unset_key']);
		$this->assertFalse(isset($this->cache['unset_key']));
	}

	// ── KeyPrefix ────────────────────────────────────────────────────────────

	public function testKeyPrefixIsolatesNamespaces(): void
	{
		$this->cache->setKeyPrefix('ns1_');
		$this->cache->set('shared_key', 'ns1_value');

		$this->cache->setKeyPrefix('ns2_');
		$this->assertFalse($this->cache->get('shared_key'));

		$this->cache->setKeyPrefix('ns1_');
		$this->assertSame('ns1_value', $this->cache->get('shared_key'));
	}

	// ── PrimaryCache ─────────────────────────────────────────────────────────

	public function testGetSetPrimaryCache(): void
	{
		$this->cache->setPrimaryCache(true);
		$this->assertTrue($this->cache->getPrimaryCache());
		$this->cache->setPrimaryCache(false);
		$this->assertFalse($this->cache->getPrimaryCache());
	}

	// ── Cache file is atomic (temp + rename) ──────────────────────────────────

	public function testCacheFilesUseHashedNames(): void
	{
		// After set, there should be exactly one .cache file.
		$this->cache->flush();
		$this->cache->set('atomic_key', 'atomic_value');

		$files = glob(self::$cacheDir . DIRECTORY_SEPARATOR . '*.cache') ?: [];
		$this->assertCount(1, $files);

		// File name should be sha1 of the unique key, NOT the raw key.
		$basename = basename($files[0], '.cache');
		$this->assertSame(40, strlen($basename), 'Cache file name should be a 40-char SHA-1 hex.');
	}

	public function testNoTempFilesLeftAfterWrite(): void
	{
		$this->cache->set('temp_key', 'temp_value');

		$tempFiles = glob(self::$cacheDir . DIRECTORY_SEPARATOR . $this->cache->getTempFilePrefix() . '*') ?: [];
		$this->assertCount(0, $tempFiles, 'No temporary files should remain after a successful write.');
	}

	// ── Expired file is cleaned up on getValue ────────────────────────────────

	public function testExpiredFileIsDeletedOnGet(): void
	{
		$this->cache->fakeNow = 1_000_000;
		$this->cache->set('delete_on_expire', 'v', 10);

		$files = glob(self::$cacheDir . DIRECTORY_SEPARATOR . '*.cache') ?: [];
		$this->assertCount(1, $files);

		$this->cache->fakeNow = 1_000_011; // past expiry
		$this->assertFalse($this->cache->get('delete_on_expire'));

		// The expired file should have been removed.
		$filesAfter = glob(self::$cacheDir . DIRECTORY_SEPARATOR . '*.cache') ?: [];
		$this->assertCount(0, $filesAfter);
	}

	// ── Cache with various value types ────────────────────────────────────────

	public function testStoresIntegerValue(): void
	{
		$this->cache->set('int_key', 12345);
		$this->assertSame(12345, $this->cache->get('int_key'));
	}

	public function testStoresFloatValue(): void
	{
		$this->cache->set('float_key', 3.14);
		$this->assertSame(3.14, $this->cache->get('float_key'));
	}

	public function testStoresBooleanTrue(): void
	{
		$this->cache->set('bool_true', true);
		$this->assertTrue($this->cache->get('bool_true'));
	}

	public function testStoresNullValue(): void
	{
		// null with expire=0 triggers delete path in TCache::set(); use expire>0
		$this->cache->set('null_key', null, 3600);
		$this->assertNull($this->cache->get('null_key'));
	}

	public function testStoresDeepNestedArray(): void
	{
		$deep = ['a' => ['b' => ['c' => ['d' => 'deep_value']]]];
		$this->cache->set('deep_key', $deep);
		$this->assertSame($deep, $this->cache->get('deep_key'));
	}

	// ── Protected helpers: now() ──────────────────────────────────────────────

	public function testNowReturnsAnIntegerCloseToCurrentTime(): void
	{
		$before = time();
		$result = $this->cache->pubNow();
		$after = time();

		$this->assertGreaterThanOrEqual($before, $result);
		$this->assertLessThanOrEqual($after, $result);
	}

	public function testFakeNowOverridesNow(): void
	{
		$this->cache->fakeNow = 42;
		$this->assertSame(42, $this->cache->pubNow());
	}

	// ── Protected helpers: isFile() ───────────────────────────────────────────

	public function testIsFileTrueForExistingFile(): void
	{
		$path = self::$cacheDir . DIRECTORY_SEPARATOR . 'testfile_' . uniqid();
		file_put_contents($path, 'x');

		$this->assertTrue($this->cache->pubIsFile($path));

		@unlink($path);
	}

	public function testIsFileFalseForMissingPath(): void
	{
		$this->assertFalse($this->cache->pubIsFile(self::$cacheDir . DIRECTORY_SEPARATOR . 'no_such_file'));
	}

	public function testIsFileFalseForDirectory(): void
	{
		$this->assertFalse($this->cache->pubIsFile(self::$cacheDir));
	}

	// ── Protected helpers: tempnam() ─────────────────────────────────────────

	public function testTempnamCreatesFileInDirectory(): void
	{
		$path = $this->cache->pubTempnam(self::$cacheDir, '.prado-test-');

		$this->assertNotFalse($path);
		$this->assertFileExists($path);
		// Use the cache's realpath-resolved directory to handle platform symlinks
		// (e.g. macOS resolves /var → /private/var).
		$this->assertStringStartsWith($this->cache->getDirectory(), $path);

		@unlink($path);
	}

	// ── Protected helpers: serialize() / unserialize() ───────────────────────

	public function testSerializeProducesStringUnserializableBackToOriginal(): void
	{
		$original = ['key' => 'value', 'nested' => [1, 2, 3]];
		$serialized = $this->cache->pubSerialize($original);

		$this->assertIsString($serialized);
		$this->assertSame($original, $this->cache->pubUnserialize($serialized));
	}

	public function testUnserializeReturnsFalseOnInvalidInput(): void
	{
		$result = $this->cache->pubUnserialize('this is not serialized data');
		$this->assertFalse($result);
	}

	// ── Protected helpers: getContents() / putContents() ─────────────────────

	public function testPutContentsAndGetContentsRoundtrip(): void
	{
		$path = self::$cacheDir . DIRECTORY_SEPARATOR . 'rw_test_' . uniqid();
		$data = 'hello world';

		$written = $this->cache->pubPutContents($path, $data);
		$this->assertIsInt($written);
		$this->assertSame(strlen($data), $written);

		$read = $this->cache->pubGetContents($path);
		$this->assertSame($data, $read);

		@unlink($path);
	}

	public function testGetContentsReturnsFalseForMissingFile(): void
	{
		$result = $this->cache->pubGetContents(self::$cacheDir . DIRECTORY_SEPARATOR . 'no_such_file_' . uniqid());
		$this->assertFalse($result);
	}

	// ── Protected helpers: unlink() ──────────────────────────────────────────

	public function testUnlinkDeletesExistingFile(): void
	{
		$path = self::$cacheDir . DIRECTORY_SEPARATOR . 'unlink_test_' . uniqid();
		file_put_contents($path, 'x');

		$result = $this->cache->pubUnlink($path);

		$this->assertTrue($result);
		$this->assertFileDoesNotExist($path);
	}

	public function testUnlinkReturnsFalseForMissingFile(): void
	{
		$result = $this->cache->pubUnlink(self::$cacheDir . DIRECTORY_SEPARATOR . 'no_such_file_' . uniqid());
		$this->assertFalse($result);
	}

	// ── Protected helpers: rename() ───────────────────────────────────────────

	public function testRenameMovesFile(): void
	{
		$src = self::$cacheDir . DIRECTORY_SEPARATOR . 'rename_src_' . uniqid();
		$dst = self::$cacheDir . DIRECTORY_SEPARATOR . 'rename_dst_' . uniqid();
		file_put_contents($src, 'rename_content');

		$result = $this->cache->pubRename($src, $dst);

		$this->assertTrue($result);
		$this->assertFileDoesNotExist($src);
		$this->assertFileExists($dst);
		$this->assertSame('rename_content', file_get_contents($dst));

		@unlink($dst);
	}

	// ── Protected helpers: chmod() ────────────────────────────────────────────

	public function testChmodSetsFilePermissions(): void
	{
		if (!function_exists('posix_getuid')) {
			$this->markTestSkipped('Test requires a POSIX environment.');
		}

		$path = self::$cacheDir . DIRECTORY_SEPARATOR . 'chmod_test_' . uniqid();
		file_put_contents($path, 'x');

		$result = $this->cache->pubChmod($path, 0o600);

		$this->assertTrue($result);
		$perms = fileperms($path) & 0o777;
		$this->assertSame(0o600, $perms);

		@unlink($path);
	}

	// ── Protected helpers: hashKeyToken() / pathFor() ────────────────────────

	public function testHashKeyTokenReturnsSha1(): void
	{
		$token = 'some_cache_key';
		$hash = $this->cache->pubHashKeyToken($token);

		$this->assertSame(40, strlen($hash));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $hash);
		$this->assertSame(sha1($token), $hash);
	}

	public function testPathForReturnsCorrectPath(): void
	{
		$key = 'test_key';
		$path = $this->cache->pubPathFor($key);
		$dir = $this->cache->getDirectory();
		$expected = $dir . DIRECTORY_SEPARATOR . sha1($key) . '.cache';

		$this->assertSame($expected, $path);
	}
}

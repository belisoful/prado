<?php

/**
 * TCacheProxy class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Caching;

use Prado\Exceptions\TConfigurationException;
use Prado\IModuleDependency;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Util\TLogger;

/**
 * TCacheProxy class.
 *
 * TCacheProxy is a transparent proxy that delegates every {@see ICache}
 * operation to another {@see TCache} module already registered with the
 * application. This lets a single logical "cache slot" (e.g. the primary
 * application cache) be hot-swapped at configuration time without changing
 * the consumers that depend on it.
 *
 * **Configuration** — set {@see getBackingCacheId BackingCacheId} to the module ID of the
 * backing cache. TCacheProxy declares that module as a required dependency via
 * {@see \Prado\IModuleDependency} so the framework initializes it first.
 *
 * **Transparency** — `get`, `set`, `add`, `delete`, and `flush` are forwarded
 * verbatim to the backing cache's public interface, preserving its key prefix,
 * TTL semantics, dependency handling, and flush behavior exactly.
 *
 * **Change logging** — calling {@see setBackingCacheId} after an id has already been
 * set logs a {@see \Prado\Util\TLogger::WARNING} message via
 * {@see \Prado\Prado::log()} so unexpected runtime swaps are visible in the
 * application log.
 *
 * Configure in application.xml:
 * ```xml
 * <module id="cache"
 *         class="Prado\Caching\TCacheProxy"
 *         BackingCacheId="fileCache"
 *         PrimaryCache="true" />
 *
 * <module id="fileCache"
 *         class="Prado\Caching\TFileCache"
 *         Directory="Application.runtime.cache"
 *         PrimaryCache="false" />
 * ```
 *
 * Or instantiate directly:
 * ```php
 * $proxy = new TCacheProxy();
 * $proxy->setBackingCacheId('fileCache');
 * $proxy->setPrimaryCache(true);
 * $proxy->init(null);
 * // All operations now delegate to the 'fileCache' module.
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 4.4.0
 * @todo
 */
class TCacheProxy extends TCache implements IModuleDependency
{
	/** @var string Module ID of the backing cache; empty until configured. */
	private string $_backingCacheId = '';

	/** @var ?TCache Lazily resolved reference to the backing cache. */
	private ?TCache $_cache = null;

	/** @var array<string, true> Lowercase event names whose handler lists are shared with the backing cache. */
	private array $_proxyEventNames = [];

	// ----------------------------------------------------------------- lifecycle

	/**
	 * Declares a required dependency on the backing cache module so that
	 * {@see \Prado\TApplication} initializes it before this proxy.
	 *
	 * @param bool $isInit `true` when collecting for the init() pass (default),
	 *   `false` when collecting for the dyPreInit pass.
	 *   TCacheProxy requires its backing cache in all phases, so `$isInit` is not used.
	 * @return ?array<int, array{id: ?string, required: bool}> dependency list,
	 *   or null when no {@see getBackingCacheId BackingCacheId} has been set yet
	 */
	public function getModuleDependencies(bool $isInit = true): ?array
	{
		$id = $this->getBackingCacheId();
		if ($id === '') {
			return null;
		}
		return [['id' => $id, 'required' => true]];
	}

	/**
	 * Initializes the proxy cache module. Throws when no
	 * {@see getBackingCacheId BackingCacheId} has been configured.
	 *
	 * @param ?\Prado\Xml\TXmlElement $config module configuration
	 * @throws TConfigurationException when {@see getBackingCacheId} is empty
	 */
	public function init($config)
	{
		if ($this->getBackingCacheId() === '') {
			throw new TConfigurationException('cacheproxy_backing_cache_id_required');
		}
		parent::init($config);
	}

	/**
	 * Reflects all public `on[A-Z]*` methods on the backing cache — including
	 * those contributed by behaviors attached to the backing cache — and shares
	 * their {@see \Prado\Util\TWeakCallableCollection} handler lists with this
	 * proxy by storing references in `$this->_e`. After attachment, handlers
	 * registered via the proxy and handlers registered directly on the backing
	 * cache are in the same list, so events raised by the cache (or its
	 * behaviors) also invoke proxy-registered handlers. Any previous attachment
	 * is detached first.
	 *
	 * Discovery is a two-pass scan: first the cache class itself, then every
	 * enabled behavior attached to it. Each candidate name is accepted only when
	 * {@see \Prado\TComponent::hasEvent} confirms the backing cache exposes it
	 * (including via its own behaviors).
	 *
	 * The backing cache must already be resolved (i.e. {@see getCache} must be
	 * callable) before invoking this method. It is called automatically by
	 * {@see getCache} on first lazy resolution.
	 */
	public function attachProxy(): void
	{
		$this->detachProxy();
		$cache = $this->getCacheDirect();
		if ($cache === null) {
			return;
		}
		$candidates = [];
		foreach ((new \ReflectionClass($cache))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			$name = $method->getName();
			if (preg_match('/^on[A-Z]/', $name)) {
				$candidates[$name] = true;
			}
		}
		foreach ($cache->getBehaviors() as $behavior) {
			if (!$behavior->getEnabled()) {
				continue;
			}
			foreach ((new \ReflectionClass($behavior))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
				$name = $method->getName();
				if (preg_match('/^on[A-Z]/', $name)) {
					$candidates[$name] = true;
				}
			}
		}
		foreach ($candidates as $name => $_) {
			if ($cache->hasEvent($name)) {
				$lname = strtolower($name);
				$this->_e[$lname] = $cache->getEventHandlers($name);
				$this->_proxyEventNames[$lname] = true;
			}
		}
	}

	/**
	 * Determines whether an event is defined on this proxy.
	 *
	 * Extends the parent check to also return `true` for event names that were
	 * injected by {@see attachProxy} from the backing cache's behaviors — these
	 * events exist in `$this->_e` but have no corresponding method on the proxy
	 * class itself, so the parent `method_exists` check alone would miss them.
	 *
	 * @param string $name the event name
	 * @return bool whether the event is defined
	 * @since 4.4.0
	 */
	public function hasEvent($name): bool
	{
		if (parent::hasEvent($name)) {
			return true;
		}
		return isset($this->_proxyEventNames[strtolower($name)]);
	}

	/**
	 * Returns the handler list for a proxy-injected event, or delegates to the
	 * parent implementation for events defined directly on this class.
	 *
	 * {@see \Prado\TComponent::getEventHandlers} gates `on*` events through
	 * `method_exists`, which misses events injected into `$this->_e` by
	 * {@see attachProxy} from backing-cache behaviors. This override intercepts
	 * those names and returns the already-shared handler list directly.
	 *
	 * @param mixed $name the event name
	 * @throws \Prado\Exceptions\TInvalidOperationException if the event is undefined
	 * @return \Prado\Collections\TWeakCallableCollection list of attached handlers
	 * @since 4.4.0
	 */
	public function getEventHandlers($name)
	{
		$lname = strtolower($name);
		if (isset($this->_proxyEventNames[$lname])) {
			return $this->_e[$lname];
		}
		return parent::getEventHandlers($name);
	}

	/**
	 * Removes from `$this->_e` all handler-list references that were installed
	 * by {@see attachProxy}, severing the shared event state with the backing
	 * cache.
	 */
	public function detachProxy(): void
	{
		foreach ($this->_proxyEventNames as $lname => $_) {
			unset($this->_e[$lname]);
		}
		$this->_proxyEventNames = [];
	}

	/**
	 * Extends the parent {@see \Prado\TComponent::isa()} check to also return
	 * `true` when the resolved backing cache is an instance of `$class`.
	 *
	 * This makes the proxy transparent for type checks — callers that test
	 * `$cache->isa(TFileCache::class)` (for example) see through the proxy to
	 * the backing cache's actual type hierarchy, including any interfaces and
	 * behaviors it exposes.
	 *
	 * When the backing cache has not yet been resolved and a
	 * {@see getBackingCacheId BackingCacheId} is set, this method lazily
	 * resolves it via {@see getCache()} — consistent with how {@see __call()},
	 * {@see __get()}, and {@see __set()} resolve the cache on demand.
	 *
	 * @param mixed|string $class class name or object to test against
	 * @return bool `true` when this proxy or its backing cache is an instance of `$class`
	 * @since 4.4.0
	 */
	public function isa($class)
	{
		if (parent::isa($class)) {
			return true;
		}
		$cache = $this->getCacheDirect();
		if ($cache === null && $this->getBackingCacheId() !== '') {
			$cache = $this->getCache();
		}
		return $cache !== null && $cache->isa($class);
	}

	// --------------------------------------------------------------- accessors

	/**
	 * @return string the module ID of the backing cache
	 */
	protected function getBackingCacheIdDirect(): string
	{
		return $this->_backingCacheId;
	}

	/**
	 * @param string $value the module ID to store directly
	 */
	protected function setBackingCacheIdDirect(string $value): void
	{
		$this->_backingCacheId = $value;
	}

	/**
	 * @return string the module ID of the backing cache
	 */
	public function getBackingCacheId(): string
	{
		return $this->getBackingCacheIdDirect();
	}

	/**
	 * Sets the module ID of the backing cache. When a non-empty id was already
	 * set and the new value differs, the change is logged at
	 * {@see \Prado\Util\TLogger::WARNING} level and the resolved cache
	 * reference is invalidated so the next operation re-resolves the module.
	 *
	 * @param string $value the module ID of the cache to proxy
	 */
	public function setBackingCacheId(string $value): void
	{
		$value = TPropertyValue::ensureString($value);
		$current = $this->getBackingCacheIdDirect();
		if ($value === $current) {
			return;
		}
		if ($current !== '') {
			$this->detachProxy();
			Prado::log(
				sprintf(
					"TCacheProxy.BackingCacheId changed from '%s' to '%s'.",
					$current,
					$value
				),
				TLogger::WARNING,
				'prado.caching'
			);
		}
		$this->setBackingCacheIdDirect($value);
		$this->setCacheDirect(null);
	}

	/**
	 * @return ?TCache the lazily resolved backing cache reference,
	 *   or null when not yet resolved
	 */
	protected function getCacheDirect(): ?TCache
	{
		return $this->_cache;
	}

	/**
	 * @param ?TCache $cache the backing cache reference to store directly
	 */
	protected function setCacheDirect(?TCache $cache): void
	{
		$this->_cache = $cache;
	}

	/**
	 * Returns the resolved backing {@see TCache} instance, resolving it lazily
	 * on first call via {@see \Prado\TApplication::getModule()}.
	 *
	 * @throws TConfigurationException when {@see getBackingCacheId} is empty
	 * @throws TConfigurationException when the referenced module does not exist
	 * @throws TConfigurationException when the referenced module is not a {@see TCache}
	 * @return TCache the backing cache module
	 */
	public function getCache(): TCache
	{
		$cacheModule = $this->getCacheDirect();
		if ($cacheModule === null) {
			$id = $this->getBackingCacheId();
			if ($id === '') {
				throw new TConfigurationException('cacheproxy_backing_cache_id_required');
			}
			$cacheModule = $this->getApplication()->getModule($id);
			if ($cacheModule === null) {
				throw new TConfigurationException('cacheproxy_cache_not_found', $id);
			}
			if (!($cacheModule instanceof TCache)) {
				throw new TConfigurationException('cacheproxy_invalid_cache_type', $id);
			}
			$this->setCacheDirect($cacheModule);
			$this->attachProxy();
			$cacheModule = $this->getCacheDirect();
		}
		return $cacheModule;
	}

	// ----------------------------------------------------------------- ICache

	/**
	 * Retrieves a value from the backing cache with the specified key.
	 *
	 * @param string $id a key identifying the cached value
	 * @return false|mixed the value stored in cache, or false on miss / expiry
	 */
	public function get($id)
	{
		return $this->getCache()->get($id);
	}

	/**
	 * Stores a value in the backing cache under the specified key.
	 *
	 * @param string $id the key identifying the value to be cached
	 * @param mixed $value the value to be cached
	 * @param int $expire TTL in seconds; 0 means never expire
	 * @param ?ICacheDependency $dependency optional invalidation dependency
	 * @return bool true on success
	 */
	public function set($id, $value, $expire = 0, $dependency = null)
	{
		return $this->getCache()->set($id, $value, $expire, $dependency);
	}

	/**
	 * Stores a value in the backing cache only when no live entry exists.
	 *
	 * @param string $id the key identifying the value to be cached
	 * @param mixed $value the value to be cached
	 * @param int $expire TTL in seconds; 0 means never expire
	 * @param ?ICacheDependency $dependency optional invalidation dependency
	 * @return bool true when the entry was stored; false when it already existed
	 */
	public function add($id, $value, $expire = 0, $dependency = null)
	{
		return $this->getCache()->add($id, $value, $expire, $dependency);
	}

	/**
	 * Deletes a value from the backing cache.
	 *
	 * @param string $id the key of the value to delete
	 * @return bool true on success
	 */
	public function delete($id)
	{
		return $this->getCache()->delete($id);
	}

	/**
	 * Deletes all values from the backing cache.
	 *
	 * @return bool true on success
	 */
	public function flush()
	{
		return $this->getCache()->flush();
	}

	// --------------------------------------------------------------- dispatch

	/**
	 * Forwards unrecognized method calls to the backing cache, making
	 * cache-specific public methods (e.g., Redis pipeline commands, Memcache
	 * stats) transparently accessible through the proxy.
	 *
	 * `dy`- and `fx`-prefixed method names are never forwarded — those belong
	 * exclusively to {@see \Prado\TComponent}'s behavior and global-event
	 * system. For all other names the backing cache is consulted first; if it
	 * does not expose the method, the call falls through to
	 * {@see \Prado\TComponent::__call()} which handles JS-property variants,
	 * behaviors, and raises {@see \Prado\Exceptions\TUnknownMethodException}
	 * for truly undefined methods.
	 *
	 * @param string $method the method name
	 * @param array $args the method arguments
	 * @return mixed the return value of the forwarded call
	 */
	public function __call($method, $args)
	{
		$prefix = substr($method, 0, 2);
		if ($prefix !== 'dy' && $prefix !== 'fx') {
			$cache = $this->getCacheDirect();
			if ($cache === null && $this->getBackingCacheId() !== '') {
				$cache = $this->getCache();
			}
			if ($cache !== null && Prado::method_visible($cache, $method)) {
				return $cache->$method(...$args);
			}
		}
		return parent::__call($method, $args);
	}

	/**
	 * Forwards property-read access to the backing cache when the property is
	 * not defined on the proxy itself. Enables cache-specific properties to be
	 * read as `$proxy->Property`.
	 *
	 * Properties owned by the proxy (including inherited ones such as
	 * `KeyPrefix`), `on`-events, and `fx`-events are handled by
	 * {@see \Prado\TComponent::__get()} without consulting the cache.
	 * Behaviors are checked last, after the cache, via the parent fallback.
	 *
	 * @param string $name the property name
	 * @throws \Prado\Exceptions\TInvalidOperationException when neither the
	 *   proxy, the backing cache, nor any attached behavior defines the property
	 * @return mixed the property value
	 */
	public function __get($name)
	{
		if (Prado::method_visible($this, 'get' . $name)
			|| Prado::method_visible($this, 'getjs' . $name)
			|| (strncasecmp($name, 'on', 2) === 0 && method_exists($this, $name))
			|| strncasecmp($name, 'fx', 2) === 0
		) {
			return parent::__get($name);
		}
		// Handler lists shared via attachProxy are served directly from _e.
		if (strncasecmp($name, 'on', 2) === 0 && isset($this->_proxyEventNames[strtolower($name)])) {
			return $this->_e[strtolower($name)];
		}
		$cache = $this->getCacheDirect();
		if ($cache === null && $this->getBackingCacheId() !== '') {
			$cache = $this->getCache();
		}
		if ($cache !== null && Prado::method_visible($cache, 'get' . $name)) {
			return $cache->{'get' . $name}();
		}
		return parent::__get($name);
	}

	/**
	 * Forwards property-write access to the backing cache when the property is
	 * not defined on the proxy itself. Enables cache-specific properties to be
	 * written as `$proxy->Property = $value`.
	 *
	 * Read-only properties defined on the proxy (a getter exists but no setter)
	 * are never forwarded — the proxy's constraint is preserved and a
	 * {@see \Prado\Exceptions\TInvalidOperationException} is raised.
	 * Behaviors are checked last, after the cache, via the parent fallback.
	 *
	 * @param string $name the property name
	 * @param mixed $value the property value
	 * @throws \Prado\Exceptions\TInvalidOperationException when the property is
	 *   read-only on the proxy, or undefined on both proxy and backing cache
	 */
	public function __set($name, $value)
	{
		if (Prado::method_visible($this, 'set' . $name)
			|| Prado::method_visible($this, 'setjs' . $name)
			|| Prado::method_visible($this, 'get' . $name)
			|| Prado::method_visible($this, 'getjs' . $name)
			|| (strncasecmp($name, 'on', 2) === 0 && method_exists($this, $name))
			|| strncasecmp($name, 'fx', 2) === 0
		) {
			return parent::__set($name, $value);
		}
		// Handler lists shared via attachProxy — attachEventHandler uses our getEventHandlers override.
		if (strncasecmp($name, 'on', 2) === 0 && isset($this->_proxyEventNames[strtolower($name)])) {
			return $this->attachEventHandler($name, $value);
		}
		$cache = $this->getCacheDirect();
		if ($cache === null && $this->getBackingCacheId() !== '') {
			$cache = $this->getCache();
		}
		if ($cache !== null && Prado::method_visible($cache, 'set' . $name)) {
			return $cache->{'set' . $name}($value);
		}
		return parent::__set($name, $value);
	}

	/**
	 * Forwards `isset()` checks to the backing cache when the property is not
	 * defined on the proxy itself. Returns true when the backing cache getter
	 * returns a non-null value.
	 *
	 * @param string $name the property name
	 * @return bool whether the property is considered set
	 */
	public function __isset($name)
	{
		if (Prado::method_visible($this, 'get' . $name)
			|| Prado::method_visible($this, 'getjs' . $name)
			|| (strncasecmp($name, 'on', 2) === 0 && method_exists($this, $name))
			|| strncasecmp($name, 'fx', 2) === 0
		) {
			return parent::__isset($name);
		}
		// Handler lists shared via attachProxy are in _e; isset means has at least one handler.
		if (strncasecmp($name, 'on', 2) === 0) {
			$lname = strtolower($name);
			if (isset($this->_e[$lname])) {
				return $this->_e[$lname]->getCount() > 0;
			}
		}
		$cache = $this->getCacheDirect();
		if ($cache === null && $this->getBackingCacheId() !== '') {
			$cache = $this->getCache();
		}
		if ($cache !== null && Prado::method_visible($cache, 'get' . $name)) {
			return $cache->{'get' . $name}() !== null;
		}
		return parent::__isset($name);
	}

	/**
	 * Forwards `unset()` to the backing cache (by calling the setter with
	 * `null`) when the property is not defined on the proxy itself.
	 *
	 * Read-only properties defined on the proxy are not forwarded.
	 *
	 * @param string $name the property name
	 * @throws \Prado\Exceptions\TInvalidOperationException when the property is
	 *   read-only on the proxy
	 */
	public function __unset($name)
	{
		if (Prado::method_visible($this, 'set' . $name)
			|| Prado::method_visible($this, 'setjs' . $name)
			|| Prado::method_visible($this, 'get' . $name)
			|| Prado::method_visible($this, 'getjs' . $name)
			|| (strncasecmp($name, 'on', 2) === 0 && method_exists($this, $name))
			|| strncasecmp($name, 'fx', 2) === 0
		) {
			parent::__unset($name);
			return;
		}
		// Handler lists shared via attachProxy — clear the shared collection.
		if (strncasecmp($name, 'on', 2) === 0) {
			$lname = strtolower($name);
			if (isset($this->_e[$lname])) {
				$this->_e[$lname]->clear();
				return;
			}
		}
		$cache = $this->getCacheDirect();
		if ($cache === null && $this->getBackingCacheId() !== '') {
			$cache = $this->getCache();
		}
		if ($cache !== null && Prado::method_visible($cache, 'set' . $name)) {
			$cache->{'set' . $name}(null);
			return;
		}
		parent::__unset($name);
	}

	// --------------------------------------------------------------- internals

	/**
	 * Satisfies the abstract contract of {@see TCache}; never invoked because
	 * the public interface delegates directly to the backing cache.
	 *
	 * @param string $key the unique key
	 * @return false
	 */
	protected function getValue($key)
	{
		return false; // @codeCoverageIgnore
	}

	/**
	 * Satisfies the abstract contract of {@see TCache}; never invoked because
	 * the public interface delegates directly to the backing cache.
	 *
	 * @param string $key the unique key
	 * @param mixed $value the value to store
	 * @param int $expire TTL in seconds
	 * @return false
	 */
	protected function setValue($key, $value, $expire)
	{
		return false; // @codeCoverageIgnore
	}

	/**
	 * Satisfies the abstract contract of {@see TCache}; never invoked because
	 * the public interface delegates directly to the backing cache.
	 *
	 * @param string $key the unique key
	 * @param mixed $value the value to store
	 * @param int $expire TTL in seconds
	 * @return false
	 */
	protected function addValue($key, $value, $expire)
	{
		return false; // @codeCoverageIgnore
	}

	/**
	 * Satisfies the abstract contract of {@see TCache}; never invoked because
	 * the public interface delegates directly to the backing cache.
	 *
	 * @param string $key the unique key
	 * @return false
	 */
	protected function deleteValue($key)
	{
		return false; // @codeCoverageIgnore
	}

	// -------------------------------------------------- cloning / serialization

	/**
	 * Clears the lazily resolved cache reference so the clone re-resolves
	 * the backing module on first use, then delegates to
	 * {@see \Prado\TComponent::__clone()} to re-attach behaviors.
	 */
	public function __clone()
	{
		$this->detachProxy();
		$this->setCacheDirect(null);
		parent::__clone();
	}

	/**
	 * Excludes transient and default-valued fields from serialization.
	 *
	 * @param array $exprops excluded-properties list, passed by reference
	 */
	protected function _getZappableSleepProps(&$exprops)
	{
		parent::_getZappableSleepProps($exprops);
		$exprops[] = "\0" . __CLASS__ . "\0_cache";
		$exprops[] = "\0" . __CLASS__ . "\0_proxyEventNames";
		if ($this->getBackingCacheIdDirect() === '') {
			$exprops[] = "\0" . __CLASS__ . "\0_backingCacheId";
		}
	}
}

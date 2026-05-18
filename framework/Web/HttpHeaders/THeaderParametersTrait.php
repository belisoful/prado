<?php

/**
 * THeaderParametersTrait trait file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\HttpHeaders;

/**
 * THeaderParametersTrait trait
 *
 * Provides a reusable parameter map for HTTP header value objects such as
 * {@see \Prado\Web\TMediaType} and {@see \Prado\Web\TContentDisposition}. The map
 * stores parameter names normalized to lowercase and their associated string values.
 *
 * **Usage.** A class that uses this trait and wants to expose array-style
 * access to its parameters must declare `implements \ArrayAccess` on the class:
 *
 * ```php
 * class MyHeaderValue implements \ArrayAccess
 * {
 *     use THeaderParametersTrait;
 *     // … the four offsetXxx methods are provided by the trait
 * }
 * ```
 *
 * **Parameter string parsing.** {@see setParameters()} accepts either an
 * associative array or a semicolon-delimited string of `name=value` pairs
 * (the portion of a header value that follows the primary token):
 *
 * ```php
 * $obj->setParameters(['charset' => 'UTF-8']);
 * $obj->setParameters('charset=UTF-8; boundary="----foo"');
 * ```
 *
 * In both forms parameter names are normalized to lowercase. When parsing a
 * string, surrounding `"` or `'` are stripped from values (they are delimiters,
 * not part of the stored value). A leading semicolon is silently ignored.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 4.3.3
 */
trait THeaderParametersTrait
{
	// =========================================================================
	// Backing field
	// =========================================================================

	/**
	 * @var array<string,string> Parameter name (lowercase) → value map.
	 */
	private array $_parameters = [];

	// =========================================================================
	// Parameter API
	// =========================================================================

	protected function getParametersDirect(): array
	{
		return $this->_parameters;
	}

	protected function setParametersDirect(array $parameters): void
	{
		$this->_parameters = $parameters;
	}

	/**
	 * Returns the raw stored value of a single parameter without going through
	 * the public getter. Returns `null` when the parameter is absent.
	 * @param string $name parameter name (case-insensitive)
	 * @return ?string the stored value, or `null` when absent.
	 */
	protected function getParameterDirect(string $name): ?string
	{
		return $this->_parameters[strtolower($name)] ?? null;
	}

	/**
	 * Sets or removes a single parameter directly, bypassing the public setter.
	 * Both `null` and `''` remove the parameter — HTTP header parameters have no
	 * meaningful empty value, so absent and empty are treated identically.
	 * @param string  $name  parameter name (case-insensitive)
	 * @param ?string $value value to store, or `null`/`''` to remove the parameter.
	 */
	protected function setParameterDirect(string $name, ?string $value): void
	{
		if ($value === null || $value === '') {
			unset($this->_parameters[strtolower($name)]);
		} else {
			$this->_parameters[strtolower($name)] = $value;
		}
	}

	/**
	 * Returns all parameters as an associative array of lowercase name → value.
	 * Delegates to {@see getParametersDirect()}.
	 * @return array<string,string>
	 */
	public function getParameters(): array
	{
		return $this->getParametersDirect();
	}

	/**
	 * Replaces the entire parameter map.
	 *
	 * When passed an **array**, each key is normalized to lowercase:
	 * ```php
	 * $obj->setParameters(['charset' => 'UTF-8', 'boundary' => '----foo']);
	 * ```
	 *
	 * When passed a **string**, the value is parsed as a semicolon-delimited
	 * sequence of `name=value` pairs — the portion of a header value string that
	 * follows the primary token:
	 * ```php
	 * $obj->setParameters('charset=UTF-8; boundary="----foo"');
	 * $obj->setParameters('; charset=UTF-8');   // leading semicolon is silently ignored
	 * ```
	 * Parameter names are normalized to lowercase; surrounding `"` or `'` are
	 * stripped from values (they serve as delimiters, not as stored content).
	 *
	 * Any previously stored parameters are always replaced entirely.
	 *
	 * @param array<string,string>|string $parameters associative array or
	 *   semicolon-delimited parameter string.
	 */
	public function setParameters(array|string $parameters): void
	{
		$normalized = [];
		if (is_string($parameters)) {
			$parts = preg_split('/\s*;\s*/', trim($parameters), -1, PREG_SPLIT_NO_EMPTY);
			foreach ($parts as $part) {
				if (preg_match('/^([a-zA-Z0-9_\-\*]+)\s*=\s*(.+)$/', trim($part), $m)) {
					$normalized[strtolower($m[1])] = trim($m[2], '"\'');
				}
			}
		} else {
			foreach ($parameters as $name => $value) {
				$normalized[strtolower($name)] = $value;
			}
		}
		$this->setParametersDirect($normalized);
	}

	/**
	 * Returns the value of a single parameter, or `null` if not present.
	 * The lookup is case-insensitive.
	 * @param string $name parameter name, e.g. `charset`
	 * @return ?string the parameter value, or `null` when absent.
	 */
	public function getParameter(string $name): ?string
	{
		return $this->getParameterDirect($name);
	}

	/**
	 * Adds, replaces, or removes a single parameter.
	 * The name is normalized to lowercase.
	 * Passing `null` or `''` removes the parameter (equivalent to calling
	 * {@see removeParameter()}); HTTP header parameters have no meaningful
	 * empty value, so absent and empty are treated identically.
	 * @param string  $name  parameter name, e.g. `charset`
	 * @param ?string $value parameter value, e.g. `UTF-8`, or `null`/`''` to remove.
	 */
	public function setParameter(string $name, ?string $value): void
	{
		$this->setParameterDirect($name, $value);
	}

	/**
	 * Removes a parameter by name.
	 * The lookup is case-insensitive; a no-op when the name is absent.
	 * @param string $name parameter name to remove
	 */
	public function removeParameter(string $name): void
	{
		$this->setParameterDirect($name, null);
	}

	/**
	 * Removes all parameters, leaving the map empty.
	 * Equivalent to `setParameters([])` but more expressive at the call site.
	 */
	public function clearParameters(): void
	{
		$this->setParametersDirect([]);
	}

	/**
	 * Returns `true` when the named parameter is present.
	 * The lookup is case-insensitive.
	 * @param string $name parameter name
	 * @return bool `true` when the parameter exists, `false` otherwise.
	 */
	public function hasParameter(string $name): bool
	{
		return array_key_exists(strtolower($name), $this->_parameters);
	}

	// =========================================================================
	// ArrayAccess — parameter pipe
	// =========================================================================

	/**
	 * Returns `true` when the named parameter exists; `false` otherwise.
	 * Delegates to {@see hasParameter()}; the name is normalized to lowercase.
	 * @param mixed $offset parameter name
	 * @return bool
	 */
	public function offsetExists(mixed $offset): bool
	{
		return $this->hasParameter((string) $offset);
	}

	/**
	 * Returns the value of the named parameter, or `null` when not present.
	 * Delegates to {@see getParameter()}; the name is normalized to lowercase.
	 * @param mixed $offset parameter name
	 * @return ?string parameter value, or `null`
	 */
	public function offsetGet(mixed $offset): mixed
	{
		return $this->getParameter((string) $offset);
	}

	/**
	 * Sets the named parameter to `$value`.
	 * Delegates to {@see setParameter()}; the name is normalized to lowercase.
	 * @param mixed $offset parameter name
	 * @param mixed $value  parameter value
	 */
	public function offsetSet(mixed $offset, mixed $value): void
	{
		$this->setParameter((string) $offset, (string) $value);
	}

	/**
	 * Removes the named parameter.
	 * Delegates to {@see removeParameter()}; the name is normalized to lowercase.
	 * A no-op when the parameter does not exist.
	 * @param mixed $offset parameter name
	 */
	public function offsetUnset(mixed $offset): void
	{
		$this->removeParameter((string) $offset);
	}
}

<?php
/**
 * TMap class
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Collections;

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Exceptions\TInvalidOperationException;
use Prado\Prado;
use Prado\TPropertyValue;
use Traversable;

/**
 * TMap class
 *
 * TMap implements a collection that takes key-value pairs.
 *
 * You can access, add or remove an item with a key by using
 * {@see itemAt}, {@see add}, and {@see remove}.
 * To get the number of the items in the map, use {@see getCount}.
 * TMap can also be used like a regular array as follows,
 * ```php
 * $map[$key]=$value; // add a key-value pair
 * unset($map[$key]); // remove the value with the specified key
 * if(isset($map[$key])) // if the map contains the key
 * foreach($map as $key=>$value) // traverse the items in the map
 * $n=count($map);  // returns the number of items in the map
 * ```
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 3.0
 * @method void dyAddItem(mixed $key, mixed $value)
 * @method void dyRemoveItem(mixed $key, mixed $value)
 * @method mixed dyNoItem(mixed $returnValue, mixed $key)
 */
class TMap extends \Prado\TComponent implements \IteratorAggregate, \ArrayAccess, \Countable
{
	/**
	 * @var array<int|string, mixed> internal data storage
	 */
	protected array $_d = [];
	/**
	 * @var ?bool whether this list is read-only
	 */
	private ?bool $_r = null;

	/**
	 * Constructor.
	 * Initializes the list with an array or an iterable object.
	 * @param null|array|\Iterator $data the intial data. Default is null, meaning no initialization.
	 * @param ?bool $readOnly whether the list is read-only, default null.
	 * @throws TInvalidDataTypeException If data is not null and neither an array nor an iterator.
	 */
	public function __construct($data = null, $readOnly = null)
	{
		parent::__construct();
		if ($data !== null) {
			$this->copyFrom($data);
			$readOnly = (bool) $readOnly;
		}
		$this->setReadOnly($readOnly);
	}

	/**
	 * @return bool whether this map is read-only or not. Defaults to false.
	 */
	public function getReadOnly(): bool
	{
		return (bool) $this->_r;
	}

	/**
	 * @param null|bool|string $value whether this list is read-only or not
	 */
	public function setReadOnly($value)
	{
		if ($value === null) {
			return;
		}
		if($this->_r === null || Prado::isCallingSelf()) {
			$this->_r = TPropertyValue::ensureBoolean($value);
		} else {
			throw new TInvalidOperationException('map_readonly_set', $this::class);
		}
	}

	/**
	 * This sets the read only property.
	 */
	protected function collapseReadOnly(): void
	{
		$this->_r = (bool) $this->_r;
	}

	/**
	 * Returns an iterator for traversing the items in the list.
	 * This method is required by the interface \IteratorAggregate.
	 * @return \Iterator an iterator for traversing the items in the list.
	 */
	public function getIterator(): \Iterator
	{
		return new \ArrayIterator($this->_d);
	}

	/**
	 * Returns the number of items in the map.
	 * This method is required by \Countable interface.
	 * @return int number of items in the map.
	 */
	public function count(): int
	{
		return $this->getCount();
	}

	/**
	 * @return int the number of items in the map
	 */
	public function getCount(): int
	{
		return count($this->_d);
	}

	/**
	 * @return array<int|string> the key list
	 */
	public function getKeys(): array
	{
		return array_keys($this->_d);
	}

	/**
	 * Returns the item with the specified key.
	 * This method is exactly the same as {@see offsetGet}.
	 * @param mixed $key the key
	 * @return mixed the element at the offset, null if no element is found at the offset
	 */
	public function itemAt($key)
	{
		return (isset($this->_d[$key]) || array_key_exists($key, $this->_d)) ? $this->_d[$key] : $this->dyNoItem(null, $key);
	}

	/**
	 * Adds an item into the map.
	 * Note, if the specified key already exists, the old value will be overwritten.
	 * @param mixed $key
	 * @param mixed $value
	 * @throws TInvalidOperationException if the map is read-only
	 * @return mixed The key of the item, which is calculated when the $key is null.
	 */
	public function add($key, $value): mixed
	{
		$this->collapseReadOnly();
		if (!$this->_r) {
			if ($key === null) {
				$this->_d[] = $value;
				$key = array_key_last($this->_d);
			} else {
				$this->_d[$key] = $value;
			}
			$this->dyAddItem($key, $value);
			return $key;
		} else {
			throw new TInvalidOperationException('map_readonly', $this::class);
		}
	}

	/**
	 * Removes an item from the map by its key.
	 * @param mixed $key the key of the item to be removed
	 * @throws TInvalidOperationException if the map is read-only
	 * @return mixed the removed value, null if no such key exists.
	 */
	public function remove($key)
	{
		if (!$this->_r) {
			if (isset($this->_d[$key]) || array_key_exists($key, $this->_d)) {
				$value = $this->_d[$key];
				unset($this->_d[$key]);
				$this->dyRemoveItem($key, $value);
				return $value;
			} else {
				return null;
			}
		} else {
			throw new TInvalidOperationException('map_readonly', $this::class);
		}
	}

	/**
	 * Removes an item from the map.  This removes all of an item from the map.
	 * @param mixed $item the item to be removed
	 * @throws TInvalidOperationException if the map is read-only
	 * @return array The array of keys and the item removed.
	 * since 4.2.3
	 */
	public function removeItem(mixed $item): array
	{
		if (!$this->_r) {
			$return = [];
			foreach ($this->toArray() as $key => $value) {
				if ($item === $value) {
					$return[$key] = $this->remove($key);
				}
			}
			return $return;
		} else {
			throw new TInvalidOperationException('map_readonly', $this::class);
		}
	}

	/**
	 * Removes all items in the map.
	 */
	public function clear(): void
	{
		foreach (array_keys($this->_d) as $key) {
			$this->remove($key);
		}
	}

	/**
	 * @param mixed $key the key
	 * @return bool whether the map contains an item with the specified key
	 */
	public function contains($key): bool
	{
		return isset($this->_d[$key]) || array_key_exists($key, $this->_d);
	}

	/**
	 * @param mixed $item the item
	 * @param bool $multiple Return an array of all the keys. Default true.
	 * @return false|mixed the key of the item in the map, false if not found.
	 * since 4.2.3
	 */
	public function keyOf($item, bool $multiple = true): mixed
	{
		if ($multiple) {
			$return = [];
			foreach ($this->toArray() as $key => $value) {
				if ($item === $value) {
					$return[$key] = $item;
				}
			}
			return $return;
		} else {
			return array_search($item, $this->_d, true);
		}
	}

	/**
	 * @return array<int|string, mixed> the list of items in array
	 */
	public function toArray(): array
	{
		return $this->_d;
	}

	/**
	 * Copies iterable data into the map.
	 * Note, existing data in the map will be cleared first.
	 * @param mixed $data the data to be copied from, must be an array or object implementing Traversable
	 * @throws TInvalidDataTypeException If data is neither an array nor an iterator.
	 */
	public function copyFrom($data): void
	{
		if (is_array($data) || $data instanceof Traversable) {
			if ($this->getCount() > 0) {
				$this->clear();
			}
			foreach ($data as $key => $value) {
				$this->add($key, $value);
			}
		} elseif ($data !== null) {
			throw new TInvalidDataTypeException('map_data_not_iterable');
		}
	}

	/**
	 * Merges iterable data into the map.
	 * Existing data in the map will be kept and overwritten if the keys are the same.
	 * @param mixed $data the data to be merged with, must be an array or object implementing Traversable
	 * @throws TInvalidDataTypeException If data is neither an array nor an iterator.
	 */
	public function mergeWith($data): void
	{
		if (is_array($data) || $data instanceof Traversable) {
			foreach ($data as $key => $value) {
				$this->add($key, $value);
			}
		} elseif ($data !== null) {
			throw new TInvalidDataTypeException('map_data_not_iterable');
		}
	}

	/**
	 * Returns whether there is an element at the specified offset.
	 * This method is required by the interface \ArrayAccess.
	 * @param mixed $offset the offset to check on
	 * @return bool
	 */
	public function offsetExists($offset): bool
	{
		return $this->contains($offset);
	}

	/**
	 * Returns the element at the specified offset.
	 * This method is required by the interface \ArrayAccess.
	 * @param mixed $offset the offset to retrieve element.
	 * @return mixed the element at the offset, null if no element is found at the offset
	 */
	public function offsetGet($offset): mixed
	{
		return $this->itemAt($offset);
	}

	/**
	 * Sets the element at the specified offset.
	 * This method is required by the interface \ArrayAccess.
	 * @param mixed $offset the offset to set element
	 * @param mixed $item the element value
	 */
	public function offsetSet($offset, $item): void
	{
		$this->add($offset, $item);
	}

	/**
	 * Unsets the element at the specified offset.
	 * This method is required by the interface \ArrayAccess.
	 * @param mixed $offset the offset to unset element
	 */
	public function offsetUnset($offset): void
	{
		$this->remove($offset);
	}

	/**
	 * Returns an array with the names of all variables of this object that should NOT be serialized
	 * because their value is the default one or useless to be cached for the next page loads.
	 * Reimplement in derived classes to add new variables, but remember to  also to call the parent
	 * implementation first.
	 * @param array $exprops by reference
	 */
	protected function _getZappableSleepProps(&$exprops)
	{
		parent::_getZappableSleepProps($exprops);
		if (empty($this->_d)) {
			$exprops[] = "\0*\0_d";
		}
		if ($this->_r === null) {
			$exprops[] = "\0" . __CLASS__ . "\0_r";
		}
	}
}

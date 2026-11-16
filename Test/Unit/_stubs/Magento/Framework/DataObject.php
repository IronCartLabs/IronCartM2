<?php

/**
 * Unit-test stub of Magento\Framework\DataObject (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework;

/**
 * Minimal key/value object with Magento's get/set/has/uns magic.
 */
class DataObject
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(protected array $_data = [])
    {
    }

    /**
     * @param array<string, mixed> $arr
     * @return $this
     */
    public function addData(array $arr)
    {
        foreach ($arr as $key => $value) {
            $this->_data[$key] = $value;
        }

        return $this;
    }

    /**
     * @param string|array<string, mixed> $key
     * @param mixed $value
     * @return $this
     */
    public function setData($key, $value = null)
    {
        if (is_array($key)) {
            $this->_data = $key;
        } else {
            $this->_data[$key] = $value;
        }

        return $this;
    }

    /**
     * @param null|string|string[] $key
     * @return $this
     */
    public function unsetData($key = null)
    {
        if ($key === null) {
            $this->_data = [];
        } else {
            foreach ((array) $key as $name) {
                unset($this->_data[$name]);
            }
        }

        return $this;
    }

    /**
     * @param string $key
     * @param string|int|null $index
     * @return mixed
     */
    public function getData($key = '', $index = null)
    {
        if ($key === '') {
            return $this->_data;
        }
        $value = $this->_data[$key] ?? null;
        if ($index !== null && is_array($value)) {
            return $value[$index] ?? null;
        }

        return $value;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function hasData($key = '')
    {
        if ($key === '') {
            return !empty($this->_data);
        }

        return array_key_exists($key, $this->_data);
    }

    /**
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        $prefix = substr($method, 0, 3);
        $key = strtolower((string) preg_replace('/(.)([A-Z])/', '$1_$2', substr($method, 3)));

        switch ($prefix) {
            case 'get':
                return $this->getData($key, $args[0] ?? null);
            case 'set':
                return $this->setData($key, $args[0] ?? null);
            case 'uns':
                return $this->unsetData($key);
            case 'has':
                return isset($this->_data[$key]);
        }

        throw new \BadMethodCallException('Invalid method ' . static::class . '::' . $method);
    }
}

<?php

/**
 * Базовый класс, позволяющий прозрачно использовать геттеры и сеттеры
 * вместо $obj->getValue() и $obj->setValue('...') сразу писать $obj->value = '...' или $x = $obj->value
 */
class BaseGetterSetter
{
	public function __get($key)
	{
		$method = "get" . ucfirst($key);
		if (method_exists($this, $method))
		{
			return $this->$method();
		}
		else
		{
			throw new Exception("В объекте " . __CLASS__ . " нет getter'а $method");
		}
	}

	public function __set($key,$value)
	{
		$method = "set" . ucfirst($key);
		if (method_exists($this, $method))
		{
			$this->$method($value);
		}
		else
		{
			throw new Exception("В объекте " . __CLASS__ . " нет getter'а $method");
		}
	}
}
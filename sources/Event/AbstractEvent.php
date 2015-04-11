<?php
/**
 * Event class AbstractEvent.
 */
namespace Moro\Migration\Event;
use \Symfony\Component\EventDispatcher\Event;

/**
 * Class AbstractEvent
 * @package Moro\Migration\Event
 */
abstract class AbstractEvent extends Event
{
	/**
	 * @var string
	 */
	protected $_serviceName;

	/**
	 * @param string $name
	 * @return $this
	 */
	public function setServiceName($name)
	{
		assert($this->_serviceName === null && is_string($name));

		$this->_serviceName = 'team-migrations.'.$name;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getServiceName()
	{
		return $this->_serviceName;
	}

	/**
	 * @return static
	 */
	public static function create()
	{
		return new static;
	}
}
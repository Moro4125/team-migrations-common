<?php
/**
 * Event class OnAskMigrationList.
 */
namespace Moro\Migration\Event;

/**
 * Class OnAskMigrationList
 * @package Moro\Migration\Event
 */
class OnAskMigrationList extends AbstractEvent
{
	/**
	 * @var array
	 */
	protected $_migrations;

	/**
	 * @var string
	 */
	protected $_errorMessage;

	/**
	 * @var int
	 */
	protected $_permanent;

	/**
	 * @param array $list
	 * @return $this
	 */
	public function setMigrations(array $list)
	{
		assert(count($list) == min(count(array_filter($list, 'is_string')), count(array_filter(array_keys($list), 'is_string'))));

		$this->_migrations = $list;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getMigrations()
	{
		return $this->_migrations ?: [];
	}

	/**
	 * @param int $time
	 * @return $this
	 */
	public function setPermanent($time)
	{
		assert((string)(int)$time === (string)$time); // ;-)

		$this->_permanent = max((int)$this->_permanent, (int)$time);
		return $this;
	}

	/**
	 * @return int
	 */
	public function getPermanent()
	{
		return (int)$this->_permanent;
	}

	/**
	 * @param string $message
	 * @return $this
	 */
	public function setErrorMessage($message)
	{
		assert(is_string($message));

		$this->_errorMessage = $message;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getErrorMessage()
	{
		return (string)$this->_errorMessage;
	}
}
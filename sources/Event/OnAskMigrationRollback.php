<?php
/**
 * Event class OnAskMigrationRollback.
 */
namespace Moro\Migration\Event;
use \Exception;

/**
 * Class OnAskMigrationRollback
 * @package Moro\Migration\Event
 */
class OnAskMigrationRollback extends AbstractEvent
{
	/**
	 * @var string
	 */
	protected $_migrationName;

	/**
	 * @var int
	 */
	protected $_step;

	/**
	 * @var Exception
	 */
	protected $_exception;

	/**
	 * @var string
	 */
	protected $_validationKey;

	/**
	 * @var callable
	 */
	protected $_callPhpScript;

	/**
	 * @param string $name
	 * @return $this
	 */
	public function setMigrationName($name)
	{
		assert(empty($this->_migrationName) && is_string($name));

		$this->_migrationName = $name;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getMigrationName()
	{
		return $this->_migrationName;
	}

	/**
	 * @param int $step
	 * @return $this
	 */
	public function setStep($step)
	{
		assert($this->_step === null && is_integer($step) && $step >= 0);

		$this->_step = $step;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getStep()
	{
		return $this->_step;
	}

	/**
	 * @param Exception $exception
	 * @return $this
	 */
	public function setException(Exception $exception)
	{
		$this->_exception = $exception;
		return $this;
	}

	/**
	 * @return \Exception
	 */
	public function getException()
	{
		return $this->_exception;
	}

	/**
	 * @param string $key
	 * @return $this
	 */
	public function setValidationKey($key)
	{
		assert($this->_validationKey === null && is_string($key));

		$this->_validationKey = $key;
		return $this;
	}

	/**
	 * @param callable $callback
	 * @return $this
	 */
	public function setCallPhpScriptCallback(callable $callback)
	{
		assert($this->_callPhpScript === null);

		$this->_callPhpScript = $callback;
		return $this;
	}

	/**
	 * @param string $hash
	 * @param string $type
	 * @param string $script
	 * @param null|string $args
	 * @return bool
	 */
	public function validateScript($hash, $type, $script, $args = null)
	{
		return sha1($this->_validationKey.$this->_migrationName.$this->_step.$type.$args.trim($script)) === $hash;
	}

	/**
	 * @param string $hash
	 * @param string $script
	 * @param null|string $args
	 */
	public function callPhpScript($hash, $script, $args)
	{
		$key = $this->_migrationName.$this->_step.'php'.$args;
		$call = $this->_callPhpScript;
		$call($this, $hash, $key, $script, $args ? json_decode($args, true) : []);
	}
}
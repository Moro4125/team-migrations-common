<?php
/**
 * Event class OnAskMigrationApply.
 */
namespace Moro\Migration\Event;
use \Exception;

/**
 * Class OnAskMigrationApply
 * @package Moro\Migration\Event
 */
class OnAskMigrationApply extends AbstractEvent
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
	 * @var int
	 */
	protected $_time;

	/**
	 * @var string
	 */
	protected $_type;

	/**
	 * @var string
	 */
	protected $_hash;

	/**
	 * @var array
	 */
	protected $_rollback;

	/**
	 * @var string
	 */
	protected $_validationKey;

	/**
	 * @var array
	 */
	protected $_arguments;

	/**
	 * @var mixed
	 */
	protected $_resultsOfCall;

	/**
	 * @var string
	 */
	protected $_script;

	/**
	 * @var Exception
	 */
	protected $_exception;

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
		assert($this->_migrationName === null && is_string($name));

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
	 * @param int $time
	 * @return $this
	 */
	public function setTime($time)
	{
		assert($this->_time === null && is_integer($time));

		$this->_time = $time;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getTime()
	{
		return $this->_time;
	}

	/**
	 * @param string $type
	 * @return $this
	 */
	public function setType($type)
	{
		assert($this->_type === null && is_string($type));

		$this->_type = $type;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getType()
	{
		return $this->_type;
	}

	/**
	 * @param array $rollback
	 * @return $this
	 */
	public function setRollback(array $rollback)
	{
		assert($this->_rollback === null);

		$this->_rollback = $rollback;
		return $this;
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
	 * @param string $hash
	 * @return $this
	 */
	public function setHash($hash)
	{
		assert($this->_hash === null && is_string($hash));

		$this->_hash = $hash;
		return $this;
	}

	/**
	 * @param int $step
	 * @return string
	 */
	public function getHash($step)
	{
		return $step
			?( isset($this->_rollback[$step][1])
				? sha1($this->_validationKey.trim($this->_rollback[$step][1]))
				: ''
			): $this->_hash;
	}

	/**
	 * @param array $arguments
	 * @return $this
	 */
	public function setArguments(array $arguments)
	{
		assert($this->_arguments === null);

		$this->_arguments = $arguments;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getArguments()
	{
		return $this->_arguments;
	}

	/**
	 * @param string $script
	 * @return $this
	 */
	public function setScript($script)
	{
		assert($this->_script === null && is_string($script));

		$this->_script = $script;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getScript()
	{
		return $this->_script;
	}

	/**
	 * @return array
	 */
	public function getRollback()
	{
		return $this->_rollback;
	}

	/**
	 * @return \Exception
	 */
	public function getException()
	{
		return $this->_exception;
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
	 * @return mixed
	 */
	public function getResultsOfCall()
	{
		return $this->_resultsOfCall;
	}

	/**
	 * @param mixed $results
	 * @return $this
	 */
	public function setResultsOfCall($results)
	{
		$this->_resultsOfCall = $results;
		return $this;
	}

	/**
	 * @param callable $callback
	 * @return $this
	 */
	public function setPhpScriptCallback(callable $callback)
	{
		assert($this->_callPhpScript === null);

		$this->_callPhpScript = $callback;
		return $this;
	}

	/**
	 * Выполнение скрипта.
	 */
	public function callPhpScript()
	{
		$call = $this->_callPhpScript;
		$this->setResultsOfCall($call($this, $this->_hash, '', $this->_script, $this->getArguments() ?: []));
	}
}
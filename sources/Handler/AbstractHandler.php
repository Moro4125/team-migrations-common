<?php
/**
 * Class AbstractHandler
 */
namespace Moro\Migration\Handler;
use \Symfony\Component\Console\Output\OutputInterface;
use \Symfony\Component\Console\Formatter\OutputFormatter;
use \Symfony\Component\Console\Formatter\OutputFormatterStyle;
use \Symfony\Component\EventDispatcher\Event;
use \Symfony\Component\EventDispatcher\EventSubscriberInterface;
use \Moro\Migration\MigrationManager;
use \Moro\Migration\Event\AbstractEvent;
use \Moro\Migration\Event\OnInitService;
use \Moro\Migration\Event\OnAskMigrationList;
use \Moro\Migration\Event\OnAskMigrationApply;
use \Moro\Migration\Event\OnAskMigrationRollback;
use \Moro\Migration\Event\OnFreeService;
use \RuntimeException;

/**
 * Class AbstractHandler
 * @package Moro\Migration\Handler
 */
abstract class AbstractHandler implements EventSubscriberInterface
{
	protected static $_lastAppliedTime = 0;

	/**
	 * @var string  The name of service for save migration information.
	 */
	protected $_serviceName;

	/**
	 * @var OutputInterface
	 */
	protected $_output;

	/**
	 * @var bool
	 */
	protected $_newLine;

	/**
	 * Returns an array of event names this subscriber wants to listen to.
	 */
	static function getSubscribedEvents()
	{
		return [
			MigrationManager::EVENT_INIT_SERVICE           => 'update',
			MigrationManager::EVENT_ASK_MIGRATION_LIST     => 'update',
			MigrationManager::EVENT_ASK_MIGRATION_APPEND   => 'update',
			MigrationManager::EVENT_ASK_MIGRATION_ROLLBACK => 'update',
			MigrationManager::EVENT_FREE_SERVICE           => 'update',
		];
	}

	/**
	 * @return int
	 */
	static protected function _generateAppliedTime()
	{
		self::$_lastAppliedTime = max(self::$_lastAppliedTime + 1, time());
		return self::$_lastAppliedTime;
	}

	/**
	 * @return string
	 */
	public function getServiceName()
	{
		return $this->_serviceName;
	}

	/**
	 * @param Event $event
	 */
	final public function update(Event $event)
	{
		if ($event instanceof AbstractEvent)
		{
			$result = false;

			if ($event->getServiceName() === $this->_serviceName)
			{
				if ($event instanceof OnAskMigrationList)
				{
					$result = $this->_onAskMigrationList($event);
				}

				if ($event instanceof OnAskMigrationApply)
				{
					$result = $this->_onAskMigrationApply($event);
				}

				if ($event instanceof OnAskMigrationRollback)
				{
					$result = $this->_onAskMigrationRollback($event);
				}
			}
			else
			{
				if ($event instanceof OnInitService)
				{
					$this->_onInitService($event);
				}

				if ($event instanceof OnFreeService)
				{
					$this->_onFreeService($event);
				}
			}

			$result === false || $event->stopPropagation();
		}
	}

	/**
	 * @param string|array $message
	 * @return $this
	 */
	public function write($message)
	{
		$this->_output && $this->_output->write(($this->_newLine ? '  ' : '')."<info>$message</info>");
		$this->_newLine = false;
		return $this;
	}

	/**
	 * @param string|array $message
	 * @return $this
	 */
	public function writeln($message)
	{
		$this->_newLine = true;
		$this->_output && $this->_output->writeln("<info>  $message  </info>");
		return $this;
	}

	/**
	 * @param string $message
	 * @throws RuntimeException
	 */
	public function error($message)
	{
		throw new RuntimeException($message);
	}

	/**
	 * @param OnInitService $event
	 */
	protected function _onInitService(OnInitService $event)
	{
		$this->_output = $event->getOutput();

		if (!$formatter = $this->_output->getFormatter())
		{
			$formatter = new OutputFormatter(true);
			$this->_output->setFormatter($formatter);
		}

		$formatter->setStyle('warning', new OutputFormatterStyle('magenta'));
		$formatter->setStyle('error',   new OutputFormatterStyle('red'));
		$formatter->setStyle('info',    new OutputFormatterStyle('blue'));
	}

	/**
	 * @param OnAskMigrationList $event
	 * @return bool
	 */
	abstract protected function _onAskMigrationList(OnAskMigrationList $event);

	/**
	 * @param OnAskMigrationApply $event
	 * @return bool
	 */
	abstract protected function _onAskMigrationApply(OnAskMigrationApply $event);

	/**
	 * @param OnAskMigrationRollback $event
	 * @return bool
	 */
	abstract protected function _onAskMigrationRollback(OnAskMigrationRollback $event);

	/**
	 * @param OnFreeService $event
	 */
	protected function _onFreeService(/** @noinspection PhpUnusedParameterInspection */ OnFreeService $event)
	{
		$this->_output = null;
	}
}
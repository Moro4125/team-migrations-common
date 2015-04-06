<?php
/**
 * Class AbstractSubscriber
 */
namespace Moro\Migration\Subscriber;
use \Symfony\Component\Console\Output\OutputInterface;
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
 * Class AbstractSubscriber
 * @package Moro\Migration\Subscriber
 */
abstract class AbstractSubscriber implements EventSubscriberInterface
{
	/**
	 * @var string  The name of service for save migration information.
	 */
	protected $_serviceName = '';

	/**
	 * @var OutputInterface
	 */
	protected $_output;

	/**
	 * Returns an array of event names this subscriber wants to listen to.
	 */
	static function getSubscribedEvents()
	{
		return [
			MigrationManager::EVENT_ASK_MIGRATION_LIST     => '__invoke',
			MigrationManager::EVENT_ASK_MIGRATION_APPEND   => '__invoke',
			MigrationManager::EVENT_ASK_MIGRATION_ROLLBACK => '__invoke',
		];
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
	final public function __invoke(Event $event)
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
		$this->_output && $this->_output->write($message);
		return $this;
	}

	/**
	 * @param string|array $message
	 * @return $this
	 */
	public function writeln($message)
	{
		$this->_output && $this->_output->writeln($message);
		return $this;
	}

	/**
	 * @param string $message
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
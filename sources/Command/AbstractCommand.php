<?php
/**
 * Class AbstractCommand
 */
namespace Moro\Migration\Command;
use \Symfony\Component\Console\Command\Command;
use \Symfony\Component\Console\Input\InputInterface;
use \Symfony\Component\Console\Output\OutputInterface;
use \Moro\Migration\MigrationManager;
use \SplObserver;
use \SplSubject;

/**
 * Class AbstractCommand
 * @package Moro\Migration\Command
 */
abstract class AbstractCommand extends Command implements SplObserver
{
	/**
	 * @var MigrationManager
	 */
	protected $_manager;

	/**
	 * @var InputInterface
	 */
	protected $_input;

	/**
	 * @var OutputInterface
	 */
	protected $_output;

	/**
	 * @var int
	 */
	protected $_lastState;

	/**
	 * @param MigrationManager $manager
	 */
	public function __construct(MigrationManager $manager)
	{
		$this->_manager = $manager;
		$manager->attach($this);
		parent::__construct();
	}

	/**
	 * @param SplSubject|MigrationManager $subject
	 */
	abstract public function update(SplSubject $subject);
}
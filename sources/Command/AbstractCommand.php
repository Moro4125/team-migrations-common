<?php
/**
 * Class AbstractCommand
 */
namespace Moro\Migration\Command;
use \Symfony\Component\Console\Command\Command;
use \Symfony\Component\Console\Input\InputInterface;
use \Symfony\Component\Console\Output\OutputInterface;
use \Symfony\Component\Console\Formatter\OutputFormatter;
use \Symfony\Component\Console\Formatter\OutputFormatterStyle;
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
	 * @var bool
	 */
	protected $_hasErrors;

	/**
	 * @param MigrationManager $manager
	 */
	public function __construct(MigrationManager $manager)
	{
		$this->_manager = $manager;
		parent::__construct();
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function initialize(InputInterface $input, OutputInterface $output)
	{
		$this->_manager->attach($this);
		$this->_input = $input;
		$this->_output = $output;
		$this->_lastState = 0;

		$formatter = new OutputFormatter(true);
		$formatter->setStyle('error', new OutputFormatterStyle('red'));
		$output->setFormatter($formatter);

		$output->writeln($this->getApplication()->getName().' '.$this->getApplication()->getVersion());
	}

	/**
	 * @param SplSubject|MigrationManager $subject
	 */
	abstract public function update(SplSubject $subject);
}
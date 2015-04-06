<?php
/**
 * Class MigrationsStatus
 */
namespace Moro\Migration\Command;
use \Symfony\Component\Console\Input\InputInterface;
use \Symfony\Component\Console\Output\OutputInterface;
use \Symfony\Component\Console\Formatter\OutputFormatter;
use \Symfony\Component\Console\Formatter\OutputFormatterStyle;
use \Moro\Migration\MigrationManager;
use \SplSubject;

/**
 * Class MigrationsStatus
 * @package Moro\Migration\Command
 */
class MigrationsStatus extends AbstractCommand
{
	/**
	 * Configures the current command.
	 */
	protected function configure()
	{
		$this->setName(defined('CLI_NAMESPACE_MIGRATIONS') ? 'status' : 'migrations:status')
			->setDescription('Show all migrations (applied, wait for rollback, prepare to apply)')
			->ignoreValidationErrors();
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return null|int null or 0 if everything went fine, or an error code
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		try
		{
			$this->_input = $input;
			$this->_output = $output;

			$formatter = new OutputFormatter(true);
			$formatter->setStyle('error', new OutputFormatterStyle('red'));
			$output->setFormatter($formatter);

			$this->_manager->doStatus();
		}
		finally
		{
			$this->_input = null;
			$this->_output = null;
		}
	}

	/**
	 * @param SplSubject|MigrationManager $subject
	 */
	public function update(SplSubject $subject)
	{
		if ($subject instanceof MigrationManager && $this->_input && $this->_output)
		{
			switch ($subject->getState())
			{
				case MigrationManager::STATE_FIRED:
					$this->_output->writeln('Current state of migrations...');
					break;

				case MigrationManager::STATE_MIGRATION_STORED:
					if ($this->_lastState != MigrationManager::STATE_MIGRATION_STORED)
					{
						$this->_output->writeln(''); $this->_output->writeln('List of applied migrations:');
					}

					$this->_output->writeln(' - "'.$subject->getStateMigrationName().'"');
					break;

				case MigrationManager::STATE_MIGRATION_ROLLBACK:
					if ($this->_lastState != MigrationManager::STATE_MIGRATION_ROLLBACK)
					{
						$this->_output->writeln(''); $this->_output->writeln('List of migration for rollback:');
					}

					$this->_output->writeln(' - "'.$subject->getStateMigrationName().'"');
					break;

				case MigrationManager::STATE_MIGRATION_APPLY:
					if ($this->_lastState != MigrationManager::STATE_MIGRATION_APPLY)
					{
						$this->_output->writeln(''); $this->_output->writeln('List of migration to perform:');
					}

					$this->_output->writeln(' - "'.$subject->getStateMigrationName().'"');
					break;

				case MigrationManager::STATE_ERROR:
					$this->_output->writeln('  <error>'.$subject->getStateLastError().'</error>');
					break;

				case MigrationManager::STATE_COMPLETE:
					$storedCount = $subject->getStatMigrationsTotal() - $subject->getStatMigrationForCommit();

					$this->_output->writeln('');
					$this->_output->writeln('Migrations was applied:  '.$storedCount);
					$this->_output->writeln('Migrations for rollback: '.$subject->getStatMigrationForRollback());
					$this->_output->writeln('Migrations for commit:   '.$subject->getStatMigrationForCommit());
					break;
			}

			$this->_lastState = $subject->getState();
		}
	}
}
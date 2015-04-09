<?php
/**
 * Class MigrationsMigrate
 */
namespace Moro\Migration\Command;
use \Symfony\Component\Console\Input\InputInterface;
use \Symfony\Component\Console\Output\OutputInterface;
use \Moro\Migration\MigrationManager;
use \SplSubject;

/**
 * Class MigrationsMigrate
 * @package Moro\Migration\Command
 */
class MigrationsMigrate extends AbstractCommand
{
	/**
	 * Configures the current command.
	 */
	protected function configure()
	{
		$this->setName(defined('CLI_NAMESPACE_MIGRATIONS') ? 'migrate' : 'migrations:migrate')
			->setDescription('Apply or roll back the migrations to synchronize environment with the current code state')
			->ignoreValidationErrors();
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return null|int null or 0 if everything went fine, or an error code
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->_manager->doMigrate($output);

		return (int)$this->_hasErrors;
	}

	/**
	 * @param SplSubject|MigrationManager $subject
	 */
	public function update(SplSubject $subject)
	{
		if ($subject instanceof MigrationManager)
		{
			switch ($subject->getState())
			{
				case MigrationManager::STATE_INITIALIZED:
					break;

				case MigrationManager::STATE_FIRED:
					if ($this->_output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE)
					{
						$this->_output->writeln('Search migrations...');
					}
					break;

				case MigrationManager::STATE_FIND_MIGRATIONS:
					if ($this->_output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE)
					{
						$this->_output->writeln('  Migration files found: '.$subject->getStatMigrationsTotal());
					}
					break;

				case MigrationManager::STATE_MIGRATIONS_ASKED:
					if ($this->_output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE)
					{
						$this->_output->writeln('  Migrations for reject: '.$subject->getStatMigrationForRollback());
						$this->_output->writeln('  Migrations for commit: '.$subject->getStatMigrationForCommit());
					}
					break;

				case MigrationManager::STATE_MIGRATION_ROLLBACK:
					if ($this->_output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE)
					{
						$this->_lastState != MigrationManager::STATE_MIGRATION_ROLLBACK && $this->_output->writeln('');
					}

					$this->_output->writeln('Rollback migration "'.$subject->getStateMigrationName().'"');
					break;

				case MigrationManager::STATE_MIGRATION_APPLY:
					if ($this->_output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE)
					{
						$this->_lastState != MigrationManager::STATE_MIGRATION_APPLY && $this->_output->writeln('');
					}

					$this->_output->writeln('Apply migration "'.$subject->getStateMigrationName().'"');
					break;

				case MigrationManager::STATE_ERROR:
					$this->_output->writeln('  <error>'.$subject->getStateLastError().'</error>');
					$this->_hasErrors = true;
					break;

				case MigrationManager::STATE_BREAK:
				case MigrationManager::STATE_COMPLETE:
					if ($this->_output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE)
					{
						$this->_output->writeln('');
						$this->_output->writeln('Results...');
						$this->_output->writeln('  Rejected migrations: '.$subject->getStatMigrationRejected());
						$this->_output->writeln('  Commited migrations: '.$subject->getStatMigrationApplied());

						if ($count = $subject->getStatErrors())
						{
							$this->_output->writeln('  <error>Number of errors: '.$count.'</error>');
						}
						else
						{
							$this->_output->writeln('  Number of errors: 0');
						}
					}
					elseif ($count = $subject->getStatErrors())
					{
						$this->_output->writeln('<error>Number of errors: '.$count.'</error>');
					}
					else
					{
						$this->_output->writeln('Migration process completed.');
					}

					break;
			}

			$this->_lastState = $subject->getState();
		}
	}
}
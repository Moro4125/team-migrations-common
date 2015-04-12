<?php
/**
 * Class MigrationsCreate
 */
namespace Moro\Migration\Command;
use \Symfony\Component\Console\Input\InputInterface;
use \Symfony\Component\Console\Output\OutputInterface;
use \Symfony\Component\Console\Helper\QuestionHelper;
use \Symfony\Component\Console\Question\Question;
use \Symfony\Component\Console\Question\ChoiceQuestion;
use \Moro\Migration\MigrationManager;
use \SplSubject;

/**
 * Class MigrationsCreate
 * @package Moro\Migration\Command
 */
class MigrationsCreate extends AbstractCommand
{
	/**
	 * Configures the current command.
	 */
	protected function configure()
	{
		$this->setName(defined('CLI_NAMESPACE_MIGRATIONS') ? 'create' : 'migrations:create')
			->setDescription('Generate INI file template for new migration')
			->ignoreValidationErrors();
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return null|int null or 0 if everything went fine, or an error code
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		if ($output->getVerbosity() == OutputInterface::VERBOSITY_QUIET)
		{
			$output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
		}

		$this->_manager->doCreate(function($files, $services) use ($input, $output) {
			$this->_output->writeln('');

			$dialog = new QuestionHelper();

			while (true)
			{
				$question = new Question('Enter migration name []: ');
				$name = $dialog->ask($input, $output, $question);
				empty($name) && $output->writeln('Migration name: '.($name = 'migration.'.date('Ymd.His', time())));

				if (!in_array($name, $files))
				{
					break;
				}

				$output->writeln("<error>Migration with name \"$name\" already exists.</error>");
			}

			if (count($services) > 1)
			{
				$this->_output->writeln('');
				array_unshift($services, 'exit');
				unset($services[0]);
				$services[0] = 'exit';

				$question = new ChoiceQuestion('Choice service name: ', $services, 0);
				$service = $dialog->ask($input, $output, $question);
			}
			else
			{
				$service = reset($services);
			}

			/** @noinspection PhpUndefinedVariableInspection */
			return [$name, ($service == 'exit') ? false : $service];
		});

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
				case MigrationManager::STATE_FIRED:
					$this->_output->write('Calculate current state of migrations...');
					break;

				case MigrationManager::STATE_FIND_MIGRATIONS:
					$this->_output->writeln(' OK.');
					break;

				case MigrationManager::STATE_ERROR:
					$this->_output->writeln('  <error>'.$subject->getStateLastError().'</error>');
					$this->_hasErrors = true;
					break;

				case MigrationManager::STATE_COMPLETE:
					$this->_output->writeln('');

					if ($subject->getStatErrors())
					{
						$this->_output->writeln('<error>Errors: '.$subject->getStatErrors().'</error>');
					}
					else
					{
						$this->_output->writeln('Complete.');
					}

					break;
			}

			$this->_lastState = $subject->getState();
		}
	}
}
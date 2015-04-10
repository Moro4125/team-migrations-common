<?php
/**
 * Trait InteractiveCommand
 */
namespace Moro\Migration\Command;
use \Symfony\Component\Console\Input\InputInterface;
use \Symfony\Component\Console\Output\OutputInterface;
use \Symfony\Component\EventDispatcher\EventDispatcher;
use \Symfony\Component\Console\Helper\QuestionHelper;
use \Symfony\Component\Console\Question\Question;
use \Symfony\Component\Console\Question\ChoiceQuestion;
use \Doctrine\DBAL\DriverManager;
use \Moro\Migration\Handler\FilesStorageHandler;
use \Moro\Migration\Handler\PdoMySQLHandler;
use \Moro\Migration\Handler\DoctrineDBALHandler;
use \PDO;
use \ArrayAccess;
use \ReflectionClass;
use \Exception;

/**
 * Trait InteractiveCommand
 * @package Moro\Migration\Command
 */
trait InteractiveCommand
{
	/**
	 * @var ArrayAccess
	 */
	protected $_container;

	/**
	 * @var EventDispatcher
	 */
	protected $_eventDispatcher;

	/**
	 * @param EventDispatcher $eventDispatcher
	 * @return $this
	 */
	public function setEventDispatcher(EventDispatcher $eventDispatcher)
	{
		$this->_eventDispatcher = $eventDispatcher;
		return $this;
	}

	/**
	 * @param ArrayAccess $container
	 * @return $this
	 */
	public function setContainer(ArrayAccess $container)
	{
		$this->_container = $container;
		return $this;
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		if ($output->getVerbosity() == OutputInterface::VERBOSITY_QUIET)
		{
			$output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
		}

		$dialog     = new QuestionHelper();
		$reflection = new ReflectionClass(get_class($this));

		$output->writeln('');
		$choices = [];

		foreach ($reflection->getMethods() as $method)
		{
			if (strncmp('setupHandler', $method->getName(), 12) === 0)
			{
				$choices[] = substr($method->getName(), 12);
			}
		}

		sort($choices);
		$choices = array_merge(['exit'], $choices);

		unset($choices[0]);
		$choices[0] = 'exit';

		$question = new ChoiceQuestion('Please, choice handler:', $choices, 0);
		$question->setMaxAttempts(3);

		$handlerCode = $dialog->ask($input, $output, $question);

		if (!$reflection->hasMethod('setupHandler'.$handlerCode))
		{
			return;
		}

		/** @var \Moro\Migration\Handler\AbstractHandler $handler */
		if (!$handler = call_user_func([$this, 'setupHandler'.$handlerCode], $input, $output, $dialog))
		{
			return;
		}

		$output->writeln('');
		$this->_eventDispatcher->addSubscriber($handler);
		$this->_container[$handler->getServiceName()] = $handler;

		/** @noinspection PhpUndefinedClassInspection */
		/** @noinspection PhpUndefinedMethodInspection */
		parent::execute($input, $output);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param QuestionHelper $dialog
	 * @return FilesStorageHandler
	 */
	public function setupHandlerFilesStorage(InputInterface $input, OutputInterface $output, QuestionHelper $dialog)
	{
		$handler = new FilesStorageHandler();

		$question = new Question('Enter path to storage folder from project root [storage]: ', 'storage');
		$handler->setStoragePath($dialog->ask($input, $output, $question));

		return $handler;
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param QuestionHelper $dialog
	 * @return \Moro\Migration\Handler\FilesStorageHandler
	 */
	public function setupHandlerPdoMySQL(InputInterface $input, OutputInterface $output, QuestionHelper $dialog)
	{
		$handler = new PdoMySQLHandler();
		list($dbHost, $dbPort, $dbName, $dbUser, $dbPass) = $this->_askDbConnectionOptions($input, $output, $dialog);

		try
		{
			$pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass ?: null, [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			]);
			$handler->setConnection($pdo);
		}
		catch (Exception $exception)
		{
			$output->writeln('');
			$output->writeln('<error>'.get_class($exception).': '.$exception->getMessage().'</error>');
			return null;
		}

		return $handler;
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param QuestionHelper $dialog
	 * @return \Moro\Migration\Handler\DoctrineDBALHandler
	 */
	public function setupHandlerDoctrineDBAL(InputInterface $input, OutputInterface $output, QuestionHelper $dialog)
	{
		$handler = new DoctrineDBALHandler();

		$drivers = array_merge(['exit'], DriverManager::getAvailableDrivers());
		unset($drivers[0]); $drivers[0] = 'exit';

		$question = new ChoiceQuestion('Please, choice DB driver:', $drivers, 0);
		$question->setMaxAttempts(3);

		if ('exit' === $driver = $dialog->ask($input, $output, $question))
		{
			return null;
		}

		list($dbHost, $dbPort, $dbName, $dbUser, $dbPass) = $this->_askDbConnectionOptions($input, $output, $dialog);

		try
		{
			$handler->setConnection(DriverManager::getConnection([
				'driver'   => $driver,
				'user'     => $dbUser,
				'password' => $dbPass,
				'host'     => $dbHost,
				'port'     => $dbPort,
				'dbname'   => $dbName,
			]));
		}
		catch (Exception $exception)
		{
			$output->writeln('');
			$output->writeln('<error>'.get_class($exception).': '.$exception->getMessage().'</error>');
			return null;
		}

		return $handler;
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param QuestionHelper $dialog
	 * @return array
	 */
	protected function _askDbConnectionOptions(InputInterface $input, OutputInterface $output, QuestionHelper $dialog)
	{
		$question = new Question('Enter database host [127.0.0.1]: ', '127.0.0.1');
		$dbHost = $dialog->ask($input, $output, $question);

		$question = new Question('Enter database port [3306]: ', '3306');
		$dbPort = $dialog->ask($input, $output, $question);

		$question = new Question('Enter database name [test]: ', 'test');
		$dbName = $dialog->ask($input, $output, $question);

		$question = new Question('Enter database user [root]: ', 'root');
		$dbUser = $dialog->ask($input, $output, $question);

		$question = new Question('Enter database password []: ', '');
		$dbPass = $dialog->ask($input, $output, $question);

		return [$dbHost, $dbPort, $dbName, $dbUser, $dbPass];
	}
}
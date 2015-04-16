<?php
/**
 * Class MigrationManager
 */
namespace Moro\Migration;
use \Symfony\Component\Console\Output\OutputInterface;
use \Symfony\Component\EventDispatcher\EventDispatcherInterface;
use \Symfony\Component\Finder\Finder;
use \Moro\Migration\Event\OnInitService;
use \Moro\Migration\Event\OnAskMigrationList;
use \Moro\Migration\Event\OnAskMigrationApply;
use \Moro\Migration\Event\OnAskMigrationRollback;
use \Moro\Migration\Event\OnFreeService;
use \ArrayAccess;
use \SplObjectStorage;
use \SplObserver;
use \SplSubject;
use \Exception;
use \ErrorException;

/**
 * Class MigrationManager
 * @package Moro\Migration
 */
class MigrationManager implements SplSubject
{
	const VERSION = '1.3.1';

	const EVENT_INIT_SERVICE           = 'team-migrations.init_service';
	const EVENT_ASK_MIGRATION_LIST     = 'team-migrations.ask_migration_list';
	const EVENT_ASK_MIGRATION_APPEND   = 'team-migrations.ask_migration_append';
	const EVENT_ASK_MIGRATION_ROLLBACK = 'team-migrations.ask_migration_rollback';
	const EVENT_FREE_SERVICE           = 'team-migrations.free_service';

	const COMPOSER_FILE = 'composer.json';

	const INI_SECTION_MIGRATION = 'migration';
	const INI_SECTION_FILTERS   = 'filters';
	const INI_SECTION_ACTIONS   = 'actions';

	const INI_KEY_CREATED     = 'created';
	const INI_KEY_SERVICE     = 'service';
	const INI_KEY_PERMANENT   = 'permanent';
	const INI_KEY_ENVIRONMENT = 'environment';
	const INI_KEY_MIGRATION   = 'migration';
	const INI_KEY_MODULE      = 'module';

	const ROLLBACK_KEY_NAME = 'name';
	const ROLLBACK_KEY_STEP = 'step';
	const ROLLBACK_KEY_TYPE = 'type';
	const ROLLBACK_KEY_ARGS = 'args';
	const ROLLBACK_KEY_CODE = 'code';
	const ROLLBACK_KEY_SIGN = 'sign';

	const ERROR_EMPTY_INI_SECTION  = 'File "%1$s.ini" does not have section "%2$s" or section is empty.';
	const ERROR_EMPTY_INI_KEY      = 'File "%1$s.ini" does not have key "%2$s" in section "%3$s".';
	const ERROR_UNKNOWN_FILTER     = 'File "%1$s.ini" has unknown filter "%2$s" in same section.';
	const ERROR_RECURSION          = 'File "%1$s.ini" has recursion in filter conditions.';
	const ERROR_EVENT_NOT_RECEIVE  = 'Service "team-migrations.%1$s" does not receive event "%2$s"';
	const ERROR_WRONG_EVENT_RESULT = 'Wrong result of event %1$s: %2$s';
	const ERROR_WRONG_PHP_SYNTAX   = 'The PHP script of migration "%1$s" has wrong syntax.';
	const ERROR_WRONG_PROJECT_NAME = 'Project name was not find in file "%1$s".';
	const ERROR_EMPTY_FILE_NAME    = 'Require not empty file name.';
	const ERROR_EMPTY_SERVICE_NAME = 'Require not empty service name.';

	const STATE_INITIALIZED        = 0;
	const STATE_FIRED              = 1;
	const STATE_FIND_MIGRATIONS    = 2;
	const STATE_MIGRATIONS_ASKED   = 3;
	const STATE_MIGRATION_STORED   = 4;
	const STATE_MIGRATION_ROLLBACK = 5;
	const STATE_MIGRATION_APPLY    = 6;
	const STATE_ERROR              = 7;
	const STATE_COMPLETE           = 8;
	const STATE_BREAK              = 9;

	const PERMANENT = 'permanent';

	/**
	 * @var int
	 */
	protected $_state;

	/**
	 * @var EventDispatcherInterface
	 */
	protected $_dispatcher;

	/**
	 * @var \ArrayAccess
	 */
	protected $_container;

	/**
	 * @var string
	 */
	protected $_projectPath;

	/**
	 * @var string
	 */
	protected $_projectName;

	/**
	 * @var string
	 */
	protected $_validationKey;

	/**
	 * @var string
	 */
	protected $_environment;

	/**
	 * @var int
	 */
	protected $_statErrors;

	/**
	 * @var int
	 */
	protected $_statMigrationsTotal;

	/**
	 * @var int
	 */
	protected $_statMigrationsStored;

	/**
	 * @var int
	 */
	protected $_statMigrationForRollback;

	/**
	 * @var int
	 */
	protected $_statMigrationForCommit;

	/**
	 * @var int
	 */
	protected $_statMigrationRejected;

	/**
	 * @var int
	 */
	protected $_statMigrationApplied;

	/**
	 * @var string
	 */
	protected $_stateMigrationName;

	/**
	 * @var string
	 */
	protected $_stateLastError;

	/**
	 * @var \SplObjectStorage
	 */
	protected $_observers;

	/**
	 * @var callable
	 */
	protected static $_call;

	/**
	 * @param null|EventDispatcherInterface $dispatcher
	 * @param null|string $root  Path to project root directory.
	 */
	public function __construct(EventDispatcherInterface $dispatcher = null, $root = null)
	{
		$dispatcher && $this->setEventDispatcher($dispatcher);
		$root && $this->setProjectPath($root);
		$this->_observers = new SplObjectStorage();
		$this->_state = self::STATE_INITIALIZED;
	}

	/**
	 * @param EventDispatcherInterface $dispatcher
	 * @return $this
	 */
	public function setEventDispatcher(EventDispatcherInterface $dispatcher)
	{
		$this->_dispatcher = $dispatcher;
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
	 * @param string $root
	 * @return $this
	 */
	public function setProjectPath($root)
	{
		assert(is_string($root));
		$this->_projectPath = realpath($root);
		return $this;
	}

	/**
	 * @return string
	 */
	public function getProjectPath()
	{
		if (empty($this->_projectPath))
		{
			for ($current = __DIR__; strlen($current) > 3; $current = dirname($current))
			{
				file_exists($current.DIRECTORY_SEPARATOR.self::COMPOSER_FILE) && $this->_projectPath = $current;
			}
		}

		return $this->_projectPath;
	}

	/**
	 * @param string $name
	 * @return $this
	 */
	public function setEnvironment($name)
	{
		assert(is_string($name));
		$this->_environment = $name;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getEnvironment()
	{
		if (empty($this->_environment))
		{
			$this->_environment = getenv('ENVIRONMENT') ?: 'production.main';
		}

		return $this->_environment;
	}

	/**
	 * @param string $key
	 * @return $this
	 */
	public function setValidationKey($key)
	{
		assert(is_string($key));
		$this->_validationKey = $key;
		return $this;
	}

	/**
	 * @return string
	 * @throws Exception
	 */
	public function getProjectName()
	{
		if (empty($this->_projectName) && $projectPath = $this->getProjectPath())
		{
			$meta = json_decode(@file_get_contents($file = $projectPath.DIRECTORY_SEPARATOR.self::COMPOSER_FILE), true);

			if (empty($meta) || empty($meta['name']))
			{
				throw new Exception(sprintf(self::ERROR_WRONG_PROJECT_NAME, $file));
			}

			$this->_projectName = $meta['name'];
		}

		return $this->_projectName;
	}

	/**
	 * @return int
	 */
	public function getState()
	{
		return $this->_state;
	}

	/**
	 * @return int
	 */
	public function getStatErrors()
	{
		return $this->_statErrors;
	}

	/**
	 * @return int
	 */
	public function getStatMigrationsTotal()
	{
		return $this->_statMigrationsTotal;
	}

	/**
	 * @return int
	 */
	public function getStatMigrationsStored()
	{
		return $this->_statMigrationsStored;
	}

	/**
	 * @return int
	 */
	public function getStatMigrationForCommit()
	{
		return $this->_statMigrationForCommit;
	}

	/**
	 * @return int
	 */
	public function getStatMigrationForRollback()
	{
		return $this->_statMigrationForRollback;
	}

	/**
	 * @return int
	 */
	public function getStatMigrationApplied()
	{
		return $this->_statMigrationApplied;
	}

	/**
	 * @return int
	 */
	public function getStatMigrationRejected()
	{
		return $this->_statMigrationRejected;
	}

	/**
	 * @return string
	 */
	public function getStateMigrationName()
	{
		return $this->_stateMigrationName;
	}

	/**
	 * @return string
	 */
	public function getStateLastError()
	{
		return $this->_stateLastError;
	}

	/**
	 * @param SplObserver $observer
	 * @return $this
	 */
	public function attach(SplObserver $observer)
	{
		if (!$this->_observers->contains($observer))
		{
			$this->_observers->attach($observer);
		}

		return $this;
	}

	/**
	 * @param SplObserver $observer
	 * @return $this
	 */
	public function detach(SplObserver $observer)
	{
		if ($this->_observers->contains($observer))
		{
			$this->_observers->detach($observer);
		}

		return $this;
	}

	/**
	 * Create INI file template for new migration.
	 *
	 * @param callable $callbackAskNameAndService
	 */
	public function doCreate(callable $callbackAskNameAndService)
	{
		assert($this->_dispatcher);
		assert($this->_container);

		$this->_clearStatProperties();
		$this->_dispatcher->dispatch(self::EVENT_INIT_SERVICE, OnInitService::create());

		try
		{
			$this->notify(self::STATE_FIRED);

			$modulesAndFiles = $this->_findModulesAndMigrationFiles();
			$migrations      = $this->_parseMigrationFiles($modulesAndFiles);

			$folders = array_map('dirname', $modulesAndFiles[$this->getProjectName()]);
			asort($folders);

			$migrationsPath = reset($folders) ?: $this->getProjectPath().DIRECTORY_SEPARATOR.'migrations';
			file_exists($migrationsPath) || mkdir($migrationsPath, 0775, true);

			$this->notify(self::STATE_FIND_MIGRATIONS);
			list($name, $service) = $callbackAskNameAndService(array_keys($folders), array_keys($migrations));

			if (empty($name))
			{
				$this->_printError(self::ERROR_EMPTY_FILE_NAME);
			}
			elseif (empty($service))
			{
				$this->_printError(self::ERROR_EMPTY_SERVICE_NAME);
			}
			else
			{
				file_put_contents($migrationsPath.DIRECTORY_SEPARATOR.$name.'.ini', implode("\n", [
					"; File $name.ini by ".(getenv('USERNAME') ?: getenv('USER') ?: 'unknown user'),
					'',
					'['.self::INI_SECTION_MIGRATION.']',
					self::INI_KEY_CREATED.'="'.date('Y-m-d H:i O').'"',
					self::INI_KEY_SERVICE.'="'.$service.'"',
					'',
					'['.self::INI_SECTION_ACTIONS.']',
					'a001="php> echo \'Migration applied!\'.PHP_EOL;"',
					'r001="php> echo \'Migration rejected!\'.PHP_EOL;"',
					'',
				]));
			}

			$this->notify(self::STATE_COMPLETE);
		}
		catch (Exception $exception)
		{
			$this->_printError($exception);
			$this->notify(self::STATE_COMPLETE);
		}
		finally
		{
			$this->_stateMigrationName = null;
			$this->_state = self::STATE_INITIALIZED;

			$this->_dispatcher->dispatch(self::EVENT_FREE_SERVICE, OnFreeService::create());
		}
	}

	/**
	 * Execute status command.
	 */
	public function doStatus()
	{
		assert($this->_dispatcher);
		assert($this->_container);

		$this->_clearStatProperties();
		$this->_dispatcher->dispatch(self::EVENT_INIT_SERVICE, OnInitService::create());

		try
		{
			$this->notify(self::STATE_FIRED);

			$modulesAndFiles = $this->_findModulesAndMigrationFiles();
			$migrations      = $this->_parseMigrationFiles($modulesAndFiles);

			$this->notify(self::STATE_FIND_MIGRATIONS);

			$actions         = $this->_askMigrationList($modulesAndFiles, $migrations);
			$actions         = $this->_sortMigrationsList($actions);

			$this->notify(self::STATE_MIGRATIONS_ASKED);
			$states = [self::STATE_MIGRATION_STORED, self::STATE_MIGRATION_ROLLBACK, self::STATE_MIGRATION_APPLY];

			foreach (array_combine($states, [$actions[2], $actions[0], $actions[1]]) as $state => $migrations)
			{
				foreach (array_keys($migrations) as $migrationName)
				{
					$this->_stateMigrationName = $migrationName;
					$this->notify($state);
				}
			}

			$this->notify(self::STATE_COMPLETE);
		}
		catch (Exception $exception)
		{
			$this->_printError($exception);
			$this->notify(self::STATE_COMPLETE);
		}
		finally
		{
			$this->_stateMigrationName = null;
			$this->_state = self::STATE_INITIALIZED;

			$this->_dispatcher->dispatch(self::EVENT_FREE_SERVICE, OnFreeService::create());
		}
	}

	/**
	 * Execute migration command.
	 *
	 * @param OutputInterface $output
	 */
	public function doMigrate(OutputInterface $output)
	{
		assert($this->_dispatcher);
		assert($this->_container);

		$this->_clearStatProperties();
		$call = self::$_call;

		$callPhpScript = function($event, $hash, $key, $script, $arguments) use ($call) {
			try
			{
				$errorReporting = error_reporting(E_ALL | E_STRICT);

				if (!$this->_evalCheckSyntax($script))
				{
					/** @noinspection PhpUndefinedMethodInspection */
					$message = sprintf(self::ERROR_WRONG_PHP_SYNTAX, $event->getMigrationName());
					/** @noinspection PhpUndefinedMethodInspection */
					throw new ErrorException($message, 0, E_PARSE, 'action N'.$event->getStep(), 0);
				}

				set_error_handler(function($severity, $message, $file = null, $line = null) {
					if (error_reporting() & $severity)
					{
						throw new ErrorException($message, 0, $severity, $file, $line);
					}
				}, E_ALL | E_STRICT);

				/** @var \Moro\Migration\Event\OnAskMigrationApply $event */
				$service = $this->_container->offsetGet($event->getServiceName());
				return $call($this->_container, $service, $arguments, trim($script), $hash, $this->_validationKey.$key);
			}
			catch (Exception $exception)
			{
				/** @noinspection PhpUndefinedMethodInspection */
				$event->setException($exception);
				return -1;
			}
			finally
			{
				/** @noinspection PhpUndefinedVariableInspection */
				error_reporting($errorReporting);
				restore_error_handler();
			}

			return null;
		};

		$this->_dispatcher->dispatch(self::EVENT_INIT_SERVICE, OnInitService::create()->setOutput($output));

		try
		{
			$this->notify(self::STATE_FIRED);

			$modulesAndFiles = $this->_findModulesAndMigrationFiles();
			$migrations      = $this->_parseMigrationFiles($modulesAndFiles);

			$this->notify(self::STATE_FIND_MIGRATIONS);

			$actions         = $this->_askMigrationList($modulesAndFiles, $migrations);
			$actions         = $this->_sortMigrationsList($actions);

			$this->notify(self::STATE_MIGRATIONS_ASKED);

			if (empty($this->_statErrors))
			{
				$actions[0] && $this->_askMigrationRollback($actions[0], $callPhpScript);
				$actions[1] && $this->_askMigrationsApply($actions[1], $modulesAndFiles, $callPhpScript);
			}

			$this->notify(self::STATE_COMPLETE);
		}
		catch (Exception $exception)
		{
			$this->_printError($exception);
			$this->notify(self::STATE_COMPLETE);
		}
		finally
		{
			$this->_stateMigrationName = null;
			$this->_state = self::STATE_INITIALIZED;

			$this->_dispatcher->dispatch(self::EVENT_FREE_SERVICE, OnFreeService::create());
		}
	}

	/**
	 * Notify any observers about state change.
	 *
	 * @param null|int $state
	 */
	public function notify($state = null)
	{
		$this->_state = intval($state) ?: $this->_state;

		/** @var \SplObserver $observer */
		foreach ($this->_observers as $observer)
		{
			$observer->update($this);
		}
	}

	/**
	 * Reset properties with statistic information.
	 */
	protected function _clearStatProperties()
	{
		$this->_statErrors               = 0;
		$this->_statMigrationsTotal      = 0;
		$this->_statMigrationForCommit   = 0;
		$this->_statMigrationForRollback = 0;
		$this->_statMigrationRejected    = 0;
		$this->_statMigrationApplied     = 0;
	}

	/**
	 * @return array
	 */
	protected function _findModulesAndMigrationFiles()
	{
		$list = [];
		$modules = [];

		$finder = Finder::create()
			->files()->name('*.ini')->name(self::COMPOSER_FILE)
			->in($this->getProjectPath())
			->followLinks()
			->ignoreDotFiles(false)
			->ignoreVCS(true);

		foreach ($finder as $file)
		{
			if (basename($file) == self::COMPOSER_FILE)
			{
				if ($composer = json_decode(file_get_contents($file), true))
				{
					$modules[$composer['name']] = [];
				}
			}
			else
			{
				if (false === strpos(file_get_contents($file), '['.self::INI_SECTION_MIGRATION.']'))
				{
					continue;
				}

				$list[dirname($file)][] = basename($file);
			}
		}

		foreach (array_keys($list) as $path)
		{
			$modulePath = $path;

			while (!file_exists($modulePath.DIRECTORY_SEPARATOR.self::COMPOSER_FILE))
			{
				$modulePath = dirname($modulePath);
			}

			if ($composer = json_decode(file_get_contents($modulePath.DIRECTORY_SEPARATOR.self::COMPOSER_FILE), true))
			{
				foreach ($list[$path] as $migration)
				{
					$list[$composer['name']][basename($migration, '.ini')] = $path.DIRECTORY_SEPARATOR.$migration;
				}
			}

			unset($list[$path]);
		}

		return array_merge($modules, $list);
	}

	/**
	 * @param array $modules
	 * @return array
	 */
	protected function _parseMigrationFiles(array $modules)
	{
		$migrations = [];
		$files = [];

		foreach ($modules as $module => $iniFiles)
		{
			foreach ($iniFiles as $path)
			{
				$files[$module.':'.basename($path, '.ini')] = $path;
			}
		}

		$checked = [];

		for ($this->_statMigrationsTotal = $maximum = count($files); $maximum && $path = reset($files); $maximum--)
		{
			$name = key($files);
			$data = parse_ini_file($path, true);

			array_shift($files);
			$size = count($files);

			if (empty($data[self::INI_SECTION_MIGRATION]))
			{
				$this->_printError(sprintf(self::ERROR_EMPTY_INI_SECTION, $name, self::INI_SECTION_MIGRATION));
				$this->_statMigrationsTotal--;
				continue;
			}

			$mSection = $data[self::INI_SECTION_MIGRATION];

			if (!$time = strtotime(@$data[self::INI_SECTION_MIGRATION][self::INI_KEY_CREATED]))
			{
				$error = sprintf(self::ERROR_EMPTY_INI_KEY, $name, self::INI_KEY_CREATED, self::INI_SECTION_MIGRATION);
				$this->_printError($error);
				$this->_statMigrationsTotal--;
				continue;
			}

			if (!empty($mSection[self::INI_KEY_PERMANENT]) && empty($mSection[self::INI_KEY_SERVICE]))
			{
				$error = sprintf(self::ERROR_EMPTY_INI_KEY, $name, self::INI_KEY_SERVICE, self::INI_SECTION_MIGRATION);
				$this->_printError($error);
				$this->_statMigrationsTotal--;
				continue;
			}

			if (empty($data[self::INI_SECTION_ACTIONS]))
			{
				$this->_printError(sprintf(self::ERROR_EMPTY_INI_SECTION, $name, self::INI_SECTION_ACTIONS));
				$this->_statMigrationsTotal--;
				continue;
			}

			if (!empty($mSection[self::INI_KEY_PERMANENT]) && $service = $mSection[self::INI_KEY_SERVICE])
			{
				if (empty($migrations[$service][self::PERMANENT]))
				{
					$migrations[$service][self::PERMANENT] = $time;
				}
				else
				{
					$migrations[$service][self::PERMANENT] = max($migrations[$service][self::PERMANENT], $time);
				}
			}

			if (!empty($data[self::INI_SECTION_FILTERS]))
			{
				$doTest = function($filter, $flag, $value, $name, $path) use (&$files, &$maximum, &$checked, $modules)
				{
					switch ($filter)
					{
						case self::INI_KEY_ENVIRONMENT:
							$value = '~^'.str_replace('%', '.*', preg_quote($value, '~')).'$~i';

							if ($flag == (preg_match($value, $this->getEnvironment()) < 1))
							{
								$maximum++;
								return false;
							}

							break;

						case self::INI_KEY_MIGRATION:
							if (!strpos($value, ':'))
							{
								$value = substr($name, 0, strpos($name, ':') + 1).$value;
							}

							if (isset($files[$value]))
							{
								$files[$name] = $path;
								return false;
							}

							if ($flag == empty($checked[$value]))
							{
								return false;
							}

							break;

						case self::INI_KEY_MODULE:
							$result = false;

							if (false !== strpos($value, '%'))
							{
								$value = '~^'.str_replace('%', '.*', preg_quote($value, '~')).'$~i';

								foreach (array_keys($modules) as $module)
								{
									$result |= preg_match($value, $module);
								}
							}
							else
							{
								$result = isset($modules[$value]);
							}

							if ($flag != $result)
							{
								$maximum++;
								return false;
							}

							break;

						case self::INI_KEY_SERVICE:
							if ($flag != isset($this->_container[$value]))
							{
								$maximum++;
								return false;
							}

							break;

						default:
							$this->_printError(sprintf(self::ERROR_UNKNOWN_FILTER, $name, $filter));
							return false;
					}

					return true;
				};

				foreach ($data[self::INI_SECTION_FILTERS] as $filter => $condition)
				{
					$orFlag = false;

					foreach (array_map('trim', explode(' or ', $condition)) as $orCondition)
					{
						$andFlag = true;

						foreach (array_map('trim', explode(' and ', $orCondition)) as $condition)
						{
							$flag = strncmp($condition, 'not ', 4) !== 0;
							$flag || $condition = trim(substr($condition, 4));

							if (!$doTest($filter, $flag, $condition, $name, $path))
							{
								$andFlag = false;
								break;
							}
						}

						if ($andFlag)
						{
							$orFlag = true;
							break;
						}
					}

					if (empty($orFlag))
					{
						$size == count($files) && $this->_statMigrationsTotal--;
						continue 2;
					}
				}
			}

			$target = empty($mSection[self::INI_KEY_SERVICE]) ? '' : $mSection[self::INI_KEY_SERVICE];

			foreach ($data[self::INI_SECTION_ACTIONS] as $index => $action)
			{
				$target && $action = $target.':'.$action;

				if (!$service = substr($action, 0, (int)strpos($action, ':')))
				{
					continue;
				}

				if (!preg_match('~^(?P<mode>[ar])(?P<step>\\d+)$~', $index, $match))
				{
					continue;
				}

				if (empty($migrations[$service][$name]))
				{
					$migrations[$service][$name] = (string)$time;
				}

				if ($match['mode'] == 'a')
				{
					$migrations[$service][$name] .= '|' . ((int)$match['step']);
				}
			}

			$checked[$name] = $path;
			$maximum++;
		}

		$maximum || $this->_statErrors || $this->_printError(sprintf(self::ERROR_RECURSION, key($files)));

		return $migrations;
	}

	/**
	 * @param array $modules
	 * @param array $migrations
	 * @return array
	 */
	protected function _askMigrationList(array $modules, array $migrations)
	{
		$actions = [ [], [], [] ];

		foreach ($migrations as $service => $list)
		{
			$event = OnAskMigrationList::create()->setServiceName($service);
			$this->_dispatcher->dispatch(self::EVENT_ASK_MIGRATION_LIST, $event);
			$result = [];

			if (!$event->isPropagationStopped())
			{
				$this->_printError(sprintf(self::ERROR_EVENT_NOT_RECEIVE, $service, self::EVENT_ASK_MIGRATION_LIST));
				($error = $event->getErrorMessage()) && $this->_printError('Error: '.$error);
				continue;
			}

			foreach ($event->getMigrations() as $key => $value)
			{
				if (!preg_match('~^(\w+([-./]\w+)*):\w+([-.]\w+)*$~', $key, $m) || !preg_match('~\d+(\|\d+)*~', $value))
				{
					$value = " \"$key\" => \"$value\".";
					$this->_printError(sprintf(self::ERROR_WRONG_EVENT_RESULT, self::EVENT_ASK_MIGRATION_LIST, $value));
					continue 2;
				}

				if (!empty($modules[$m[1]]))
				{
					$result[$key] = $value;
				}
			}

			$permanentLine = max($event->getPermanent(), empty($list[self::PERMANENT]) ? 0 : $list[self::PERMANENT]);
			$t = [array_diff_key($result, $list), array_diff_key($list, $result), array_intersect_key($list, $result)];

			foreach ($t as $mode => $tempList)
			{
				foreach ($tempList as $migrationName => $meta)
				{
					$target = &$actions[$mode];

					if ($migrationName == self::PERMANENT)
					{
						continue;
					}

					if ($mode == 0 && $permanentLine >= (int)$meta)
					{
						$target = &$actions[2];
					}

					if ($mode == 0)
					{
						$target[$migrationName]['name'][] = $service;
					}

					foreach (explode('|', $meta) as $index => $value)
					{
						if (empty($target[$migrationName]['time']))
						{
							$target[$migrationName]['time'] = (int)$value;
							$target[$migrationName]['list'] = [];
						}

						if ($index)
						{
							$target[$migrationName]['list'][(int)$value] = $service;
						}
						else
						{
							$target[$migrationName]['time'] = min($target[$migrationName]['time'], (int)$value);
						}
					}
				}
			}
		}

		return $actions;
	}

	/**
	 * @param array $actions
	 * @return array
	 */
	protected function _sortMigrationsList($actions)
	{
		foreach ($actions as $index => &$target)
		{
			uasort($target, $index ? function ($a, $b)
			{
				return $a['time'] - $b['time'];
			} : function ($a, $b)
			{
				return $b['time'] - $a['time'];
			});

			foreach ($target as &$target2)
			{
				$index ? ksort($target2['list']) : krsort($target2['list']);
			}

			unset($target2);
		}

		$this->_statMigrationsStored     = count($actions[2]);
		$this->_statMigrationForRollback = count($actions[0]);
		$this->_statMigrationForCommit   = count($actions[1]);

		return $actions;
	}

	/**
	 * @param array $actions
	 * @param callable $callPhpScript
	 */
	protected function _askMigrationRollback(array $actions, callable $callPhpScript)
	{
		foreach ($actions as $migrationName => $meta)
		{
			$error = false;
			$this->_stateMigrationName = $migrationName;
			$this->notify(self::STATE_MIGRATION_ROLLBACK);

			foreach ($meta['list'] as $step => $service)
			{
				$event = OnAskMigrationRollback::create()
					->setServiceName($service)
					->setMigrationName($migrationName)
					->setStep($step)
					->setValidationKey((string)$this->_validationKey)
					->setCallPhpScriptCallback($callPhpScript);

				$this->_dispatcher->dispatch(self::EVENT_ASK_MIGRATION_ROLLBACK, $event);

				if ($exception = $event->getException())
				{
					$error = true;
					$this->_printError($exception);
				}
			}

			foreach ($meta['name'] as $service)
			{
				$event = OnAskMigrationRollback::create()
					->setServiceName($service)
					->setMigrationName($migrationName)
					->setStep(0);

				$this->_dispatcher->dispatch(self::EVENT_ASK_MIGRATION_ROLLBACK, $event);

				if ($exception = $event->getException())
				{
					$error = true;
					$this->_printError($exception);
				}
			}

			$error || $this->_statMigrationRejected++;
		}
	}

	/**
	 * @param array $actions
	 * @param array $modules
	 * @param callable $callPhpScript
	 */
	protected function _askMigrationsApply(array $actions, array $modules, callable $callPhpScript)
	{
		foreach ($actions as $migrationName => $meta)
		{
			$this->_stateMigrationName = $migrationName;
			$this->notify(self::STATE_MIGRATION_APPLY);

			$error = false;
			$cFlag = false;
			$rollback = [];

			list($module, $file) = explode(':', $migrationName);
			$migrationData = parse_ini_file($modules[$module][$file], true);
			$migrationTime = strtotime($migrationData[self::INI_SECTION_MIGRATION][self::INI_KEY_CREATED]);
			$migrationFile = [trim(file_get_contents($modules[$module][$file]))];
			$migrationBack = [];
			$migrationPath = dirname($modules[$module][$file]);
			$targetService = @$migrationData[self::INI_SECTION_MIGRATION][self::INI_KEY_SERVICE];

			$isPermanent = !empty($migrationData[self::INI_SECTION_MIGRATION][self::INI_KEY_PERMANENT]);

			foreach ($migrationData['actions'] as $index => $action)
			{
				$targetService && $action = $targetService.':'.$action;

				if (!($position = strpos($action, ':')) || !($step = (int)substr($index, 1)))
				{
					continue;
				}

				$service = substr($action, 0, $position);
				$path = substr($action, $position + 1);

				if (strncmp($index, 'a', 1) === 0)
				{
					if ($temp = strpos($path, '>'))
					{
						$args = '';
						$type = substr($path, 0, $temp);
						$code = substr($path, $temp + 1);

						if ($type == 'php' && strncmp('<'.'?php', $code, 5) !== 0)
						{
							$code = '<'.'?php '.$code;
						}
					}
					else
					{
						$args = substr($path, $argsPos = strpos($path, '?') ?: strlen($path));
						$name = substr($path, 0, $argsPos);
						$type = substr($name, strrpos($name, '.') + 1);
						$code = ($type == 'php')
							? '<'.'?php return require(\''.$migrationPath . DIRECTORY_SEPARATOR . $name.'\');'
							: file_get_contents($migrationPath . DIRECTORY_SEPARATOR . $name);
					}

					$args && parse_str(substr($args, 1), $args);
					$migrationFile[$step] = [
						'type' => $type,
						'code' => trim($code),
						'args' => $args
					];
				}
				elseif (strncmp($index, 'r', 1) === 0)
				{

					if ($temp = strpos($path, '>'))
					{
						$type = substr($path, 0, $temp);
						$code = trim(substr($path, $temp + 1));

						if ($type == 'php' && strncmp('<'.'?php', $code, 5) !== 0)
						{
							$code = '<'.'?php '.$code;
						}
					}
					else
					{
						$type = substr($path, strrpos($path, '.') + 1);
						$name = substr($path, 0, strpos($path, '?') ?: strlen($path));
						$code = file_exists($migrationPath . DIRECTORY_SEPARATOR . $name)
							? file_get_contents($migrationPath . DIRECTORY_SEPARATOR . $name)
							: '';
					}

					$migrationBack[$service][$step] = [
						self::ROLLBACK_KEY_NAME => $migrationName,
						self::ROLLBACK_KEY_STEP => $step,
						self::ROLLBACK_KEY_TYPE => $type,
						self::ROLLBACK_KEY_ARGS => null,
						self::ROLLBACK_KEY_CODE => trim($code),
					];
				}
			}

			foreach ($meta['list'] as $step => $service)
			{
				$event = OnAskMigrationApply::create()
					->setServiceName($service)
					->setMigrationName($migrationName)
					->setStep($step)
					->setTime($migrationTime)
					->setType($migrationFile[$step]['type'])
					->setHash(sha1($this->_validationKey.$migrationFile[$step]['code']))
					->setArguments($migrationFile[$step]['args'] ?: [])
					->setScript($migrationFile[$step]['code'])
					->setValidationKey((string)$this->_validationKey)
					->setPhpScriptCallback($callPhpScript);

				$this->_dispatcher->dispatch(self::EVENT_ASK_MIGRATION_APPEND, $event);

				if (isset($migrationBack[$service][$step]))
				{
					$result = is_array($event->getResultsOfCall()) ? $event->getResultsOfCall() : null;
					$migrationBack[$service][$step][self::ROLLBACK_KEY_ARGS] = $result ? json_encode($result) : null;

					$signature = sha1($this->_validationKey.implode('', $migrationBack[$service][$step]));
					$migrationBack[$service][$step][self::ROLLBACK_KEY_SIGN] = $signature;
				}

				if ($exception = $event->getException())
				{
					$error = true;
					$this->_printError($exception);
					break;
				}

				$cFlag |= $event->isPropagationStopped();
				isset($migrationBack[$service][$step]) && ($rollback[$step] = $service);
			}

			if ($error)
			{
				$migrationBack = array_intersect_key($migrationBack, array_fill_keys($rollback, 0));
				$meta['list'] = array_intersect_key($meta['list'], $rollback);

				foreach ($migrationBack as $service => &$list)
				{
					$list = array_intersect_key($list, $rollback);
				}

				unset($list);
			}

			foreach (array_unique($meta['list']) as $service)
			{
				$rollbackScripts = isset($migrationBack[$service]) ? $migrationBack[$service] : [];

				foreach ($rollbackScripts as &$record)
				{
					if (empty($record[self::ROLLBACK_KEY_SIGN]))
					{
						$record[self::ROLLBACK_KEY_SIGN] = sha1($this->_validationKey.implode('', $record));
					}
				}

				$event = OnAskMigrationApply::create()
					->setServiceName($service)
					->setMigrationName($migrationName)
					->setStep(0)
					->setTime($migrationTime)
					->setType($isPermanent ? self::PERMANENT : 'ini')
					->setHash(sha1($this->_validationKey.trim($migrationFile[0])))
					->setScript($migrationFile[0])
					->setValidationKey((string)$this->_validationKey)
					->setRollback($rollbackScripts);

				$this->_dispatcher->dispatch(self::EVENT_ASK_MIGRATION_APPEND, $event);

				if ($exception = $event->getException())
				{
					$error = true;
					$this->_printError($exception);
				}
			}

			if ($error)
			{
				$this->_statMigrationRejected--;

				$list = [$migrationName => ['name' => $meta['list'], 'list' => $rollback]];
				$this->_askMigrationRollback($list, $callPhpScript);

				break;
			}
			elseif ($cFlag)
			{
				$this->_statMigrationApplied++;
			}
		}
	}

	/**
	 * @param string $code
	 * @return bool
	 */
	protected function _evalCheckSyntax($code)
	{
		$braces = 0;
		$inString = 0;

		// We need to know if braces are correctly balanced.
		// This is not trivial due to variable interpolation
		// which occurs in heredoc, backticked and double quoted strings
		foreach (token_get_all($code) as $token)
		{
			if (is_array($token))
			{
				switch ($token[0])
				{
					case T_CURLY_OPEN:
					case T_DOLLAR_OPEN_CURLY_BRACES:
					case T_START_HEREDOC:
						++$inString;
						break;

					case T_END_HEREDOC:
						--$inString;
						break;
				}
			}
			else if ($inString & 1)
			{
				switch ($token)
				{
					case '`':
					case '"':
						--$inString;
						break;
				}
			}
			else
			{
				switch ($token)
				{
					case '`':
					case '"':
						++$inString;
						break;

					case '{':
						++$braces;
						break;

					case '}':
						if ($inString)
						{
							--$inString;
						}
						elseif (--$braces < 0)
						{
							return false;
						}

						break;
				}
			}
		}

		// Unbalanced braces would break the eval below
		if ($braces ?( $code = false ): true)
		{
			ob_start(); // Catch potential parse error messages
			$code = eval('if(0){?'.'>' . $code . '}'); // Put $code in a dead code sandbox to prevent its execution
			ob_end_clean();
		}

		return false !== $code;
	}

	/**
	 * @param \Exception|string $error
	 */
	protected function _printError($error)
	{
		if ($error instanceof Exception)
		{
			$line = $error->getLine();
			$error = 'Error: "'.$error->getMessage().'" in '.$error->getFile().($line ? ' ('.$line.').' : '.');
		}

		$state = $this->_state;
		$this->_stateLastError = $error;
		$this->_statErrors++;

		$this->notify(self::STATE_ERROR);
		$this->_stateLastError = null;
		$this->_state = $state;
	}

	/**
	 * @param callable $call
	 */
	static public function setPhpScriptCall(callable $call)
	{
		assert(self::$_call === null);
		self::$_call = $call;
	}
}

MigrationManager::setPhpScriptCall(
	function(ArrayAccess $container, $service, $arguments, $script, $signature, $key)
	{
		assert(is_object($container) && is_object($service) && is_array($arguments));
		if (sha1($key.$script) !== $signature) throw new Exception('Bad signature!');
		$results = eval('unset($script, $signature); unset($key);'.'?'.">$script\n");
		return is_array($results) ? $results :( empty($arguments) ? 0 : $arguments );
	}
);

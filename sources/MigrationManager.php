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

/**
 * Class MigrationManager
 * @package Moro\Migration
 */
class MigrationManager implements SplSubject
{
	const EVENT_INIT_SERVICE           = 'migration.init_service';
	const EVENT_ASK_MIGRATION_LIST     = 'migration.ask_migration_list';
	const EVENT_ASK_MIGRATION_APPEND   = 'migration.ask_migration_append';
	const EVENT_ASK_MIGRATION_ROLLBACK = 'migration.ask_migration_rollback';
	const EVENT_FREE_SERVICE           = 'migration.free_service';

	const COMPOSER_FILE = 'composer.json';

	const INI_SECTION_MIGRATION = 'migration';
	const INI_SECTION_FILTERS   = 'filters';
	const INI_SECTION_ACTIONS   = 'actions';

	const INI_KEY_CREATED     = 'created';
	const INI_KEY_SERVICE     = 'service';
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
	const ERROR_UNKNOWN_FILTER     = 'File "%1$s.ini" has unknown filter "%2$s" in same section.';
	const ERROR_RECURSION          = 'File "%1$s.ini" has recursion in filter conditions.';
	const ERROR_EVENT_NOT_RECEIVE  = 'Service "%1$s" does not receive event "%2$s"';
	const ERROR_WRONG_EVENT_RESULT = 'Wrong result of event %1$s: %2$s';

	const STATE_INITIALIZED        = 1;
	const STATE_FIRED              = 2;
	const STATE_FIND_MIGRATIONS    = 3;
	const STATE_MIGRATIONS_ASKED   = 4;
	const STATE_MIGRATION_ROLLBACK = 5;
	const STATE_MIGRATION_APPLY    = 6;
	const STATE_ERROR              = 7;
	const STATE_COMPLETE           = 8;
	const STATE_BREAK              = 9;

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
	 * Execute migration command.
	 *
	 * @param OutputInterface $output
	 */
	public function migrate(OutputInterface $output)
	{
		assert($this->_dispatcher);
		assert($this->_container);

		$this->_clearStatProperties();
		$call = self::$_call;

		$callPhpScript = function($event, $hash, $key, $script, $arguments) use ($call) {
			try
			{
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
		catch (BreakException $exception)
		{
			$this->notify(self::STATE_BREAK);
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

		$finder = Finder::create()
			->files()->name('*.ini')
			->in($this->getProjectPath())
			->followLinks()
			->ignoreDotFiles(true)->ignoreVCS(true);

		foreach ($finder as $file)
		{
			if (false === strpos(file_get_contents($file), '['.self::INI_SECTION_MIGRATION.']'))
			{
				continue;
			}

			$list[dirname($file)][] = basename($file);
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
				$moduleName = $composer['name'];

				foreach ($list[$path] as $migration)
				{
					$list[$moduleName][basename($migration, '.ini')] = $path.DIRECTORY_SEPARATOR.$migration;
				}
			}

			unset($list[$path]);
		}

		return $list;
	}

	/**
	 * @param array $modulesAndFiles
	 * @return array
	 */
	protected function _parseMigrationFiles(array $modulesAndFiles)
	{
		$migrations = [];
		$files = [];

		foreach ($modulesAndFiles as $module => $iniFiles)
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

			if (!$time = strtotime(@$data[self::INI_SECTION_MIGRATION][self::INI_KEY_CREATED]))
			{
				$this->_printError(sprintf(self::ERROR_EMPTY_INI_SECTION, $name, self::INI_SECTION_MIGRATION));
				$this->_statMigrationsTotal--;
				continue;
			}

			if (empty($data[self::INI_SECTION_ACTIONS]))
			{
				$this->_printError(sprintf(self::ERROR_EMPTY_INI_SECTION, $name, self::INI_SECTION_ACTIONS));
				$this->_statMigrationsTotal--;
				continue;
			}

			if (isset($data[self::INI_SECTION_FILTERS]))
			{
				foreach ($data[self::INI_SECTION_FILTERS] as $filter => $condition)
				{
					$flag = strncmp($condition, 'not ', 4) !== 0;
					$flag || $condition = substr($condition, 4);

					switch ($filter)
					{
						case self::INI_KEY_ENVIRONMENT:
							$condition = '~^'.str_replace('%', '.*', preg_quote($condition, '~')).'$~i';

							if ($flag == (preg_match($condition, $this->getEnvironment()) < 1))
							{
								$maximum++;
								continue 3;
							}

							break;

						case self::INI_KEY_MIGRATION:
							if (!strpos($condition, ':'))
							{
								$condition = substr($name, 0, strpos($name, ':') + 1).$condition;
							}

							if (isset($files[$condition]))
							{
								$files[$name] = $path;
								continue 3;
							}

							if ($flag == empty($checked[$condition]))
							{
								continue 3;
							}

							break;

						case self::INI_KEY_MODULE:
							if ($flag != isset($modulesAndFiles[$condition]))
							{
								$maximum++;
								continue 3;
							}

							break;

						default:
							$this->_printError(sprintf(self::ERROR_UNKNOWN_FILTER, $name, $filter));
							$this->_statMigrationsTotal--;
							continue 3;
					}
				}
			}

			$target = @$data[self::INI_SECTION_MIGRATION][self::INI_KEY_SERVICE];

			foreach ($data[self::INI_SECTION_ACTIONS] as $index => $action)
			{
				$target && $action = $target.':'.$action;

				if (strncmp($index, 'a', 1) === 0 || strncmp($index, 'r', 1) === 0)
				{
					$service = substr($action, 0, strpos($action, ':'));

					if (empty($migrations[$service][$name]))
					{
						$migrations[$service][$name] = $time;
					}

					if (strncmp($index, 'a', 1) === 0)
					{
						$migrations[$service][$name] .= '|' . ((int)substr($index, 1));
					}
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
		$actions = [ [], [] ];

		foreach ($migrations as $service => $list)
		{
			$event = OnAskMigrationList::create()->setServiceName($service);
			$this->_dispatcher->dispatch(self::EVENT_ASK_MIGRATION_LIST, $event);
			$result = [];

			if (!$event->isPropagationStopped())
			{
				$this->_printError(sprintf(self::ERROR_EVENT_NOT_RECEIVE, $service, self::EVENT_ASK_MIGRATION_LIST));
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

			foreach ([array_diff_key($result, $list), array_diff_key($list, $result)] as $mode => $tempList)
			{
				$target = &$actions[$mode];

				foreach ($tempList as $migrationName => $meta)
				{
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
						elseif ($index)
						{
							$target[$migrationName]['list'][(int)$value] = $service;
						}
						else
						{
							$target[$migrationName]['time'] = min($target[$migrationName]['time'], (int)$value);
						}
					}
				}

				unset($target);
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

		unset($target);
		$this->_statMigrationForRollback = count($actions[0]);
		$this->_statMigrationForCommit = count($actions[1]);

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
				$event = OnAskMigrationApply::create()
					->setServiceName($service)
					->setMigrationName($migrationName)
					->setStep(0)
					->setTime($migrationTime)
					->setType('ini')
					->setHash(sha1($this->_validationKey.trim($migrationFile[0])))
					->setScript($migrationFile[0])
					->setValidationKey((string)$this->_validationKey)
					->setRollback(isset($migrationBack[$service]) ? $migrationBack[$service] : []);

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
	 * @param \Exception|string $error
	 */
	protected function _printError($error)
	{
		if ($error instanceof Exception)
		{
			$error = 'Error: "'.$error->getMessage().'" in '.$error->getFile().' ('.$error->getLine().")";
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

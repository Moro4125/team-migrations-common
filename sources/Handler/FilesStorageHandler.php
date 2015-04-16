<?php
/**
 * Class FilesStorageHandler
 */
namespace Moro\Migration\Handler;
use \Moro\Migration\MigrationManager;
use \Moro\Migration\Event\OnAskMigrationList;
use \Moro\Migration\Event\OnAskMigrationApply;
use \Moro\Migration\Event\OnAskMigrationRollback;
use \DirectoryIterator;
use \Exception;

/**
 * Class AbstractSubscriber
 * @package Moro\Migration\Handler
 *
 * Please, do not remember set property $storagePath and create storage folder.
 */
class FilesStorageHandler extends AbstractHandler
{
	const MIGRATION_FOLDER   = '.migrations';
	const DATE_TIME_FORMAT   = 'Y-m-d H:i:s';
	const COMPOSER_FILE      = 'composer.json';

	const ERROR_EMPTY_STORAGE_PATH = 'Property "storagePath" is empty.';
	const ERROR_BAD_STORAGE_PATH   = 'Access to storage path "%1$s" is denied.';

	const KEY_NAME      = 'name';
	const KEY_TYPE      = 'type';
	const KEY_STEP      = 'step';
	const KEY_CREATED   = 'created_at';
	const KEY_APPLIED   = 'applied_at';
	const KEY_SCRIPT    = 'script';
	const KEY_ARGUMENTS = 'arguments';
	const KEY_SIGNATURE = 'signature';

	/**
	 * @var string  The name of service for save migration information.
	 */
	protected $_serviceName = 'team-migrations.files-storage';

	/**
	 * @var string  The root path of current project.
	 */
	protected $_projectPath = '';

	/**
	 * @var string  The path to the directory with clients files.
	 */
	protected $_storagePath = '';

	/**
	 * @param string $path
	 * @return $this
	 */
	public function setProjectPath($path)
	{
		assert(is_string($path));
		$this->_projectPath = $path;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getProjectPath()
	{
		if (empty($this->_projectPath))
		{
			for ($current = dirname(__DIR__); strlen($current) > 3; $current = dirname($current))
			{
				file_exists($current.DIRECTORY_SEPARATOR.self::COMPOSER_FILE) && $this->_projectPath = $current;
			}
		}

		return $this->_projectPath;
	}

	/**
	 * @param string $path
	 * @return $this
	 */
	public function setStoragePath($path)
	{
		assert(is_string($path));

		if (strlen($path) > 2 && $path[0] != '/' && $path[1] != ':')
		{
			$path = $this->getProjectPath().DIRECTORY_SEPARATOR.$path;
		}

		$this->_storagePath = $path;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getStoragePath()
	{
		return $this->_storagePath;
	}

	/**
	 * @return string
	 */
	public function getMigrationsPath()
	{
		return $this->_storagePath.DIRECTORY_SEPARATOR.self::MIGRATION_FOLDER;
	}

	/**
	 * @param OnAskMigrationList $event
	 * @return bool
	 */
	protected function _onAskMigrationList(OnAskMigrationList $event)
	{
		if (empty($this->_storagePath))
		{
			$event->setErrorMessage(self::ERROR_EMPTY_STORAGE_PATH);
			return false;
		}

		if (!file_exists($this->_storagePath) || !is_dir($this->_storagePath) || !is_writable($this->_storagePath))
		{
			$event->setErrorMessage(sprintf(self::ERROR_BAD_STORAGE_PATH, $this->_storagePath));
			return false;
		}

		$pathMigrations = $this->getMigrationsPath();
		$migrations = [];

		if (file_exists($pathMigrations) && is_dir($pathMigrations))
		{
			foreach (new DirectoryIterator($pathMigrations) as $fileInfo)
			{
				if ($fileInfo->isFile() && $record = $this->_readFileAsRecord($fileInfo->getPathname(), true))
				{
					if (array_diff_key([self::KEY_NAME => 1, self::KEY_CREATED => 1, self::KEY_STEP => 1], $record))
					{
						continue;
					}

					if (empty($migrations[$record[self::KEY_NAME]]))
					{
						$migrations[$record[self::KEY_NAME]] = (string)strtotime($record[self::KEY_CREATED]);
					}

					if ((int)$record[self::KEY_STEP])
					{
						$migrations[$record[self::KEY_NAME]] .= '|' . $record[self::KEY_STEP];
					}

					if (!empty($record[self::KEY_TYPE]) && $record[self::KEY_TYPE] == MigrationManager::PERMANENT)
					{
						$event->setPermanent((string)strtotime($record[self::KEY_CREATED]));
					}
				}
			}
		}

		$event->setMigrations($migrations);
		return true;
	}

	/**
	 * @param OnAskMigrationApply $event
	 * @return bool
	 */
	protected function _onAskMigrationApply(OnAskMigrationApply $event)
	{
		$pathMigrations = $this->getMigrationsPath();

		if ($step = $event->getStep())
		{
			switch ($event->getType())
			{
				case 'php':
					$event->callPhpScript();
					break;

				default:
					$message = sprintf(self::ERROR_UNKNOWN_TYPE, $event->getType(), $event->getMigrationName());
					$event->setException(new Exception($message));
			}
		}
		else
		{
			$list = $event->getRollback();
			$list[0] = [
				MigrationManager::ROLLBACK_KEY_TYPE => $event->getType(),
				MigrationManager::ROLLBACK_KEY_CODE => $event->getScript(),
				MigrationManager::ROLLBACK_KEY_ARGS => null,
				MigrationManager::ROLLBACK_KEY_SIGN => $event->getHash(0),
			];

			foreach ($list as $step => $data)
			{
				$record = [
					self::KEY_NAME      => $event->getMigrationName(),
					self::KEY_STEP      => $step,
					self::KEY_CREATED   => date(self::DATE_TIME_FORMAT, $event->getTime()),
					self::KEY_APPLIED   => date(self::DATE_TIME_FORMAT, self::_generateAppliedTime()),
					self::KEY_TYPE      => $data[MigrationManager::ROLLBACK_KEY_TYPE],
					self::KEY_SCRIPT    => $data[MigrationManager::ROLLBACK_KEY_CODE],
					self::KEY_ARGUMENTS => $data[MigrationManager::ROLLBACK_KEY_ARGS],
					self::KEY_SIGNATURE => $data[MigrationManager::ROLLBACK_KEY_SIGN],
				];

				$name = preg_replace('~[^-A-Za-z0-9.]~', '_', strtr($event->getMigrationName(), ':', '.')).'.'.$step;
				$file = $pathMigrations.DIRECTORY_SEPARATOR.sha1($name);
				$this->_writeRecordAsFile($record, $file);
			}
		}

		return true;
	}

	/**
	 * @param OnAskMigrationRollback $event
	 * @return bool
	 */
	protected function _onAskMigrationRollback(OnAskMigrationRollback $event)
	{
		$step = $event->getStep();
		$name = preg_replace('~[^-A-Za-z0-9.]~', '_', strtr($event->getMigrationName(), ':', '.')).'.'.$step;
		$file = $this->getMigrationsPath().DIRECTORY_SEPARATOR.sha1($name);

		if ($step && file_exists($file) && $record = $this->_readFileAsRecord($file))
		{
			switch ($type = empty($record[self::KEY_TYPE]) ? '~NULL~' : $record[self::KEY_TYPE])
			{
				case 'php':
					$arguments = empty($record[self::KEY_ARGUMENTS]) ? '' : $record[self::KEY_ARGUMENTS];
					$event->callPhpScript($record[self::KEY_SIGNATURE], $record[self::KEY_SCRIPT], $arguments);
					break;

				default:
					$message = sprintf(self::ERROR_UNKNOWN_TYPE, $type, $event->getMigrationName());
					$event->setException(new Exception($message));
			}
		}

		@unlink($file);
		return true;
	}

	/**
	 * @param string $path
	 * @param null|bool $withoutScript
	 * @return array
	 */
	protected function _readFileAsRecord($path, $withoutScript = null)
	{
		$record = [];

		if ($handler = @fopen($path, 'r'))
		{
			try
			{
				while ($line = trim(fgets($handler)))
				{
					if ($pos = strpos($line, ':'))
					{
						$key = rtrim(substr($line, 0, $pos));
						$value = ltrim(substr($line, $pos + 1));
						$value = str_replace("[CR]", "\r", str_replace("[LF]", "\n", $value));
						$record[$key] = $value;
					}
				}

				if (!$withoutScript)
				{
					$record[self::KEY_SCRIPT] = '';

					while (!feof($handler))
					{
						$record[self::KEY_SCRIPT] .= fread($handler, 0xFFFF);
					}
				}
			}
			finally
			{
				fclose($handler);
			}
		}

		return $record;
	}

	/**
	 * @param array $record
	 * @param string $path
	 * @throws Exception
	 */
	protected function _writeRecordAsFile(array $record, $path)
	{
		if ($handler = @fopen($path, 'x'))
		{
			try
			{
				try
				{
					$script = isset($record[self::KEY_SCRIPT]) ? trim($record[self::KEY_SCRIPT]) : false;
					unset($record[self::KEY_SCRIPT]);

					foreach ($record as $key => $value)
					{
						if ($value === null)
						{
							continue;
						}

						$line = $key.': '.str_replace("\r", "[CR]", str_replace("\n", "[LF]", trim($value)))."\n";
						fputs($handler, $line);
					}

					if ($script !== false)
					{
						fputs($handler, "\n");
						fputs($handler, $script, strlen($script));
					}
				}
				finally
				{
					fclose($handler);
				}
			}
			catch (Exception $exception)
			{
				@unlink($path);
				throw $exception;
			}
		}
	}
}
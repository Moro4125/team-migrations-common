<?php
/** @var $container  \ArrayAccess */
/** @var $service  \Moro\Migration\Handler\FilesStorageHandler */
/** @var $arguments *///* Arguments for this script (as GET query).
$projectPath = realpath($service->getProjectPath());
$storagePath = realpath($service->getStoragePath());
$results = []; // Results, that will used in "free.vcs.ignore.php" as arguments.

if (strlen($projectPath) < strlen($storagePath) && strncmp($projectPath, $storagePath, strlen($projectPath)) === 0)
{
	$path = substr($storagePath, strlen($projectPath) + 1);
	$results[] = $path;

	foreach (['.gitignore', '.hgignore', '.bzrignore'] as $fileName)
	{
		if (file_exists($projectPath.DIRECTORY_SEPARATOR.$fileName))
		{
			$content = file_get_contents($projectPath.DIRECTORY_SEPARATOR.$fileName);
			$pattern = '~^'.preg_quote(strtr($path, DIRECTORY_SEPARATOR, '/').'/', '~').'\\s*$~m';

			if (!preg_match($pattern, $content))
			{
				$content = PHP_EOL.'# Add by migration "moro/team-migrations-common:update.vcs.ignore".';
				$content.= PHP_EOL.strtr($path, DIRECTORY_SEPARATOR, '/').'/';
				file_put_contents($projectPath.DIRECTORY_SEPARATOR.$fileName, $content, FILE_APPEND);

				$results[] = $fileName;
			}
		}
	}
}

return $results;
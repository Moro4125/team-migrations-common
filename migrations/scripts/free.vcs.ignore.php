<?php
/** @var $container  \ArrayAccess */
/** @var $service  \Moro\Migration\Subscriber\ResourceSubscriber */
/** @var $arguments *///* Arguments for this script (as GET query).
$projectPath = realpath($service->getProjectPath());

if ($arguments && $path = array_shift($arguments))
{
	foreach ($arguments as $fileName)
	{
		if (file_exists($projectPath.DIRECTORY_SEPARATOR.$fileName))
		{
			$content = file_get_contents($projectPath.DIRECTORY_SEPARATOR.$fileName);
			$pattern = '~^'.preg_quote(strtr($path, DIRECTORY_SEPARATOR, '/').'/', '~').'\\s*$~m';
			$content = preg_replace($pattern, '', $content);
			$pattern = '~^'.preg_quote('# Add by migration "moro/team-migrations-common:update.vcs.ignore".', '~').'\\s*$~m';
			$content = preg_replace($pattern, '', $content);
			file_put_contents($projectPath.DIRECTORY_SEPARATOR.$fileName, $content);
		}
	}
}
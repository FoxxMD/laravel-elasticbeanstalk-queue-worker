<?php

function generateProgram($connection, $queue, $tries, $sleep, $numProcs, $delay, $startSecs)
{
	$program = <<<EOT

[program:$queue]
command=sudo php artisan queue:work $connection --queue=$queue --tries=$tries --sleep=$sleep --delay=$delay --daemon
directory=/var/app/current/
autostart=true
autorestart=true
process_name=$queue-%(process_num)s
numprocs=$numProcs
startsecs=$startSecs

EOT;

	return $program;
}

function getEBWorkerConfig($path)
{
	if (null === $path)
	{
		return null;
	}
	else
	{
		$filePath = $path . '/elasticbeanstalkworker.php';
		echo 'File path for worker config: ' . $filePath . PHP_EOL;
		if (is_file($filePath) && is_readable($filePath))
		{
			return include($filePath);
		}
		echo 'Worker config is not a file or is not readable. Skipping.' . PHP_EOL;

		return null;
	}
}

echo 'Starting supervisor configuration parsing.' . PHP_EOL;

// determine what directory we are in
$deployDirectory = null;
if (is_dir('/var/app/ondeck'))
{
	$deployDirectory = '/var/app/ondeck';
}
else if (is_dir('/var/app/current'))
{
	$deployDirectory = '/var/app/current';
}

// set location of our clean supervisor config
$superLocation = $deployDirectory . '/.ebextensions/supervisord.conf';

// determine config directory
$configDirectory = null;
if (is_dir($deployDirectory . '/config'))
{
	$configDirectory = $deployDirectory . '/config';
}
else
{
	echo 'Could not find project configuration directory, oh well.' . PHP_EOL;
}

// determine user supervisord.conf location
$relativeConfigFilePath = null;
if (null !== $workerConfig = getEBWorkerConfig($configDirectory))
{
	$relativeConfigFilePath = $workerConfig['supervisorConfigPath'];
}

if (null !== $relativeConfigFilePath)
{
	$absoluteConfigFilePath = $deployDirectory . '/' . $relativeConfigFilePath;
	echo 'User-supplied supervisor config path: ' . $absoluteConfigFilePath . PHP_EOL;
	$programs = file_get_contents($relativeConfigFilePath);
	if (false === $programs)
	{
		echo 'Tried to parse user-supplied supervisord.conf but it failed!' . PHP_EOL;
		exit(1);
	}
	else
	{
		echo 'Found user supervisor.conf file! Using it instead of generating from environmental variables.' . PHP_EOL;
	}
	$writeType = 0;
}
else
{
	$writeType = FILE_APPEND;

	if (null !== $relativeConfigFilePath)
	{
		echo 'Found path for user-supplied supervisord.conf but it was not a valid file. Continuing with parsing from environmental variables.' . PHP_EOL;
	}
	else
	{
		echo 'No user-supplied supervisord.conf found. Generating one from environmental variables.' . PHP_EOL;
	}

	$envLocation = $deployDirectory . '/jsonEnv';
	$vars        = json_decode(file_get_contents($envLocation), true);
	$envVars     = array_change_key_case($vars); // convert keys to lower case so environmental variables don't have to be case-sensitive

	$programs = '';

	foreach ($envVars as $key => $val)
	{
		if (strpos($key, 'queue') !== false && strpos($key, 'queue_driver') === false)
		{
			$tryKey       = substr($key, 5) . 'tries'; //get queue $key + tries to see if custom tries is set
			$sleepKey     = substr($key, 5) . 'sleep'; //get queue $key + sleep to see if custom sleep is set
			$numProcKey   = substr($key, 5) . 'numprocs'; //get queue $key + num process to see if custom number of processes is set
			$startSecsKey = substr($key, 5) . 'startsecs'; //get queue $key + number of seconds the process should stay up
			$delayKey     = substr($key, 5) . 'delay'; //get queue $key + delay in seconds before a job should re-enter the ready queue

			$tries      = isset($envVars[ $tryKey ]) ? $envVars[ $tryKey ] : 5;
			$sleep      = isset($envVars[ $sleepKey ]) ? $envVars[ $sleepKey ] : 5;
			$numProcs   = isset($envVars[ $numProcKey ]) ? $envVars[ $numProcKey ] : 1;
			$startSecs  = isset($envVars[ $startSecsKey ]) ? $envVars[ $startSecsKey ] : 1;
			$delay      = isset($envVars[ $delayKey]) ? $envVars[ $delayKey ] : 0;
			$connection = isset($envVars['queue_driver']) ? $envVars['queue_driver'] : 'beanstalkd';
			$programs .= generateProgram($connection, $val, $tries, $sleep, $numProcs, $delay, $startSecs);
		}
	}
}


file_put_contents($superLocation, $programs . PHP_EOL, $writeType);


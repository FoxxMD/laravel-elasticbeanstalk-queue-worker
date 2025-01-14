<?php

function generateProgram($connection, $queue, $tries, $sleep, $numProcs, $delay, $startSecs, $environmentVal)
{
    $queueVal = $queue !== null ? " --queue=$queue" : '';
    $program = <<<EOT

[program:$queue]
process_name=%(program_name)s_%(process_num)02d
command=php artisan queue:work $connection$queueVal --tries=$tries --sleep=$sleep --delay=$delay --daemon
directory=/var/app/current/
autostart=true
autorestart=true
numprocs=$numProcs
startsecs=$startSecs
user=webapp
environment=$environmentVal

EOT;

    return $program;
}

function getEBWorkerConfig($path)
{
    if (null === $path) {
        return null;
    } else {
        $filePath = $path . '/elasticbeanstalkworker.php';
        echo 'File path for worker config: ' . $filePath . PHP_EOL;
        if (is_file($filePath) && is_readable($filePath)) {
            return include($filePath);
        }
        echo 'Worker config is not a file or is not readable. Skipping.' . PHP_EOL;

        return null;
    }
}

echo 'Starting supervisor configuration parsing.' . PHP_EOL;

$deployDirectory = '/var/app/current';

// set location of our clean supervisor config
$superLocation = $deployDirectory . '/.platform/queue-worker/supervisord.conf';

// determine config directory
$configDirectory = null;
if (is_dir($deployDirectory . '/config')) {
    $configDirectory = $deployDirectory . '/config';
} else {
    echo 'Could not find project configuration directory, oh well.' . PHP_EOL;
}

// determine user supervisord.conf location
$relativeConfigFilePath = null;
if (null !== $workerConfig = getEBWorkerConfig($configDirectory)) {
    $relativeConfigFilePath = $workerConfig['supervisorConfigPath'];
}

if (null !== $relativeConfigFilePath) {
    $absoluteConfigFilePath = $deployDirectory . '/' . $relativeConfigFilePath;
    echo 'User-supplied supervisor config path: ' . $absoluteConfigFilePath . PHP_EOL;
    $programs = file_get_contents($relativeConfigFilePath);
    if (false === $programs) {
        echo 'Tried to parse user-supplied supervisord.conf but it failed!' . PHP_EOL;
        exit(1);
    } else {
        echo 'Found user supervisor.conf file! Using it instead of generating from environmental variables.' . PHP_EOL;
    }
    $writeType = 0;
} else {
    $writeType = FILE_APPEND;

    if (null !== $relativeConfigFilePath) {
        echo 'Found path for user-supplied supervisord.conf but it was not a valid file. Continuing with parsing from environmental variables.' . PHP_EOL;
    } else {
        echo 'No user-supplied supervisord.conf found. Generating one from environmental variables.' . PHP_EOL;
    }

    $envLocation  = $deployDirectory . '/jsonEnv';
    $envVars      = json_decode(file_get_contents($envLocation), true);
    $lowerEnvVars = array_change_key_case($envVars); // convert keys to lower case so environmental variables don't have to be case-sensitive

    $envKvArray = [];
    foreach ($envVars as $key => $val) {
        if (
            ctype_alnum($val) // alphanumeric doesn't need quotes
            || (strpos($val, '"') === 0 && strrpos($val, '"') === count($val) - 1) // if the value is already quoted don't double-quote it
        ) {
            $formattedVal = $val;
        } else { // otherwise put everything in quotes for environment param http://supervisord.org/configuration.html#program-x-section-values
            $formattedVal = "\"{$val}\"";
        }
        $envKvArray[] = "{$key}={$formattedVal}";
    }
    $envKv = implode(',', $envKvArray);

    $programs = '';

    $isBeanstalk = false;
    if (!empty($lowerEnvVars['queue_connection']) && $lowerEnvVars['queue_connection'] === 'beanstalkd') {
        $isBeanstalk = true;
    }

    foreach ($lowerEnvVars as $key => $val) {
        if (substr($key, 0, 5) === 'queue' && $key !== 'queue_connection') {
            $tryKey       = substr($key, 5) . 'tries'; //get queue $key + tries to see if custom tries is set
            $sleepKey     = substr($key, 5) . 'sleep'; //get queue $key + sleep to see if custom sleep is set
            $numProcKey   = substr($key, 5) . 'numprocs'; //get queue $key + num process to see if custom number of processes is set
            $startSecsKey = substr($key, 5) . 'startsecs'; //get queue $key + number of seconds the process should stay up
            $delayKey     = substr($key, 5) . 'delay'; //get queue $key + delay in seconds before a job should re-enter the ready queue

            $tries      = isset($lowerEnvVars[$tryKey]) ? $lowerEnvVars[$tryKey] : 5;
            $sleep      = isset($lowerEnvVars[$sleepKey]) ? $lowerEnvVars[$sleepKey] : 5;
            $numProcs   = isset($lowerEnvVars[$numProcKey]) ? $lowerEnvVars[$numProcKey] : 1;
            $startSecs  = isset($lowerEnvVars[$startSecsKey]) ? $lowerEnvVars[$startSecsKey] : 1;
            $delay      = isset($lowerEnvVars[$delayKey]) ? $lowerEnvVars[$delayKey] : 0;
            // if using beanstalk connection should always be beanstalkd and specify tube in queue, otherwise use queue driver name as connection
            $connection = $isBeanstalk ? 'beanstalkd' : $val;
            // if not using beanstalk we don't need queue probably
            $queue = $isBeanstalk ? $val : null;
            $programEnvArray = $envKvArray;
            // if any vars need to be specific per worker this is where to put them
            // $programEnvArray[] =
            $programs   .= generateProgram($connection, $queue, $tries, $sleep, $numProcs, $delay, $startSecs, implode(',', $programEnvArray));
        }
    }
}

file_put_contents($superLocation, $programs . PHP_EOL, $writeType);

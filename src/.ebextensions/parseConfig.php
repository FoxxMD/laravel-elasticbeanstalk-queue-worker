<?php

function generateProgram($connection, $queue, $tries, $sleep, $numProcs, $startSecs){
    $program = <<<EOT

[program:$queue]
command=sudo php artisan doctrine:queue:work $connection --queue=$queue --tries=$tries --sleep=$sleep --daemon
directory=/var/app/current/
autostart=true
autorestart=true
process_name=$queue-%(process_num)s
numprocs=$numProcs
startsecs=$startSecs

EOT;
    return $program;
}

//relative dir is not working for some reason, need to test them all!
$envLocation = '';
if(file_exists('/var/app/ondeck/jsonEnv')){
    $envLocation = '/var/app/ondeck/jsonEnv';
} else if(file_exists('/var/app/current/jsonEnv')){
    $envLocation = '/var/app/current/jsonEnv';
}
$envVars = json_decode(file_get_contents($envLocation), true);

$programs = '';

foreach($envVars as $key => $val){
    if(strpos(strtolower($key), 'queue') !== false && strpos(strtolower($key), 'queue_driver') === false){
        $tryKey = substr($key, 10) . 'Tries'; //get queue $key + tries to see if custom tries is set
        $sleepKey = substr($key, 5) . 'Sleep'; //get queue $key + sleep to see if custom sleep is set
        $numProcKey = substr($key, 5) . 'NumProcs'; //get queue $key + num process to see if custom number of processes is set
        $startSecsKey = substr($key, 5) . 'StartSecs'; //get queue $key + number of seconds the process should stay up

        $tries = isset($envVars[$tryKey]) ? $envVars[$tryKey] : 5;
        $sleep = isset($envVars[$sleepKey]) ? $envVars[$sleepKey] : 5;
        $numProcs = isset($envVars[$numProcKey]) ? $envVars[$numProcKey] : 1;
        $startSecs = isset($envVars[$startSecsKey]) ? $envVars[$startSecsKey] : 1;
        $connection = isset($envVars['QUEUE_DRIVER']) ? $envVars['QUEUE_DRIVER'] : 'beanstalkd';
        $programs .= generateProgram($connection, $val, $tries, $sleep, $numProcs, $startSecs);
    }
}
$superLocation = '';
if(file_exists('/var/app/ondeck/.ebextensions/supervisord.conf')){
    $superLocation = '/var/app/ondeck/.ebextensions/supervisord.conf';
} else if(file_exists('/var/app/current/.ebextensions/supervisord.conf')){
    $superLocation = '/var/app/current/.ebextensions/supervisord.conf';
}

file_put_contents($superLocation, $programs.PHP_EOL, FILE_APPEND);


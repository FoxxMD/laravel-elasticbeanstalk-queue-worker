# Laravel 6 - 12.x Queue Worker for Elastic Beanstalk

*Use your Laravel application as a worker to consume queues on AWS Elasticbeanstalk*

Laravel provides a [wonderful array](https://laravel.com/docs/12.x/queues) of drivers for consuming queues within your application as well as [some documentation](https://laravel.com/docs/12.x/queues#supervisor-configuration) on how to manage your application with [Supervisord](http://supervisord.org/) when it is acting as a worker.

Unfortunately that's where the documentation ends. There is no guidance on how to manage multiple workers from a devops context which is a huge bummer. But don't worry fam I've got your covered.

**This package enables your Laravel application to manage itself, as a worker, in an [AWS Elasticbeanstalk](https://aws.amazon.com/elasticbeanstalk/) environment.**

**It provides these features:**

* **Automated installation of supervisor on first-time deployment**
* **Automatic updating of supervisor configuration upon deployment**
* **Two supervisor configuration deployment options:**
  * **Parsing of EB environmental variables to generate supervisor config**
  * **Or using a pre-built supervisor config supplied in project**

## Amazon Linux 1 is deprecated and Amazon Linux 2023 is recommended

Amazon Linux 1 (AL1) is already retired, even Amazon Linux 2 (AL2) will soon going to be unsupported too, so it's recommended to migrate to use Amazon Linux 2023 (AL2023)
https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/using-features.migration-al.html
Starts from this release will only support AL2023, please use previous releases for use in AL2, with this release will also drop support for Laravel 5 (PHP 7) since EB only support starting from PHP 8.1

# Let's get down to business

## Installation

Require this package

```bash
composer require "foxxmd/laravel-elasticbeanstalk-queue-worker"
```

or for Amazon Linux 2,

```bash
composer require "foxxmd/laravel-elasticbeanstalk-queue-worker@^1.0"
```

**After installing the package you can either:**

Publish using artisan

```bash
php artisan vendor:publish --tag=ebworker
```

**OR**

Copy everything from `src/.ebextensions` into your own `.ebextensions` folder and everything from `src/.platform` into your own `.platform` folder manually

**Note:** This library only consists of the EB deploy steps -- the provider is only for a convenience -- so if you want to you can modify/consolidate the `.ebextensions` / `.platform` folder if you're not into me overwriting your stuff.

Don't forget to add +x permission to the EB Platform Hooks scripts ([no longer required for Amazon Linux platform that released on or after April 29, 2022](https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/platforms-linux-extend.hooks.html#platforms-linux-extend.hooks.more))

```bash
find .platform -type f -iname "*.sh" -exec chmod +x {} +
```


## Configuration

### Enable Worker Mode

In order for worker deployment to be active you **must** add this environmental to your elasticbeanstalk environment configuration:

```bash
IS_WORKER = true
```

**If this variable is false or not present the deployment will not run**

### Set Queue Driver / Connection

Set the [driver](https://laravel.com/docs/12.x/queues#driver-prerequisites) in your your EB environmental variables:

```bash
QUEUE_CONNECTION = [driver]
```

### Add Queues

All queues are configured using EB environmental variables with the following syntax:

**Note**: brackets are placeholders only, do not use them in your actual configuration

```bash
queue[QueueName]     = [driver]   # Required. The name of the queue that should be run. Value is driver/connection name
[QueueName]NumProcs  = [value]       # Optional. The number of instances supervisor should run for this queue. Defaults to 1
[QueueName]Tries     = [value]       # Optional. The number of times the worker should attempt to run in the event an unexpected exit code occurs. Defaults to 5
[QueueName]Sleep     = [value]       # Optional. The number of seconds the worker should sleep if no new jobs are in the queue. Defaults to 5
[QueueName]StartSecs = [value]       # Optional. How long a job should run for to be considered successful. Defaults to 1
[QueueName]Delay     = [value]       # Optional. Time in seconds a job should be delayed before returning to the ready queue. Defaults to 0
```

Add one `queue[QueueName] = [driver]` entry in your EB environmental variables for each queue you want to run. The rest of the parameters are optional.

That's it! On your next deploy supervisor will have its configuration updated/generated and start chugging along on your queues.

## Using Your Own `supervisord.conf`

Using your own, pre-built supervisor config file is easy too.

Simply set the location of the file in the published `elasticbeanstalkworker.php` config file:

```php
<?php

return array(
	/*
	 * The path of the supervisord.conf file to be used INSTEAD OF generating one from environmental variables. Note that this can be null if you do not have one.
	 *
	 * This path is RELATIVE to the root of your application.
	 * EX:
	 * Absolute Path: /Users/dev/coding/MyProject/config/mysupervisord.conf
	 * Path to use:   config/mysupervisord.conf
	 */
	'supervisorConfigPath' => 'config/mysupervisord.conf`
);
```

Now during the deploy process your configuration file will be used instead of generating one.

Note: you can check `eb-hooks.log` for your EB environment to verify if the deploy process detected and deployed your file. Search for `Starting supervisor configuration parsing.` in the log.

# But how does it work?

Glad you asked. It's a simple process but required a ton of trial and error to get right (kudos to AWS for their lack of documentation)

EB applications can contain a [folder](https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/ebextensions.html) that provides advanced configuration for an EB environment, called `.ebextensions`.

EB applications since AL2 can contain [platform hooks](https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/platforms-linux-extend.html) that provides hook scripts for an EB environment, called `.platform`.

This package uses AWS commands files in this folder to detect, install, and update supervisor and its configuration and then run it for you.

### 1. Ingress Supervisor rules

Supervisor requires port 9001 to be open if you want to access its web monitor. This is an optional step and can be removed if you don't need it by deleting `00supervisordIngress.config`

### 2. Parse Queue Configuration

`parseConfig.php` looks for either a user-supplied `supervisord.conf` file specified in configuration. If one exists then it is used.

Otherwise `parseConfig.php` looks for a json file generated earlier that contains all of the environmental variables configured for elastic beanstalk. It then parses out any queue configurations found (see `Add Queues`) section above and generates a supervisor program for each as well as supplying each program with all the environmental variables set for EB. The program to be generated looks like this:

```bash
[program:$queue]
process_name=%(program_name)s_%(process_num)02d
command=php artisan queue:work $connection --queue=$queue --tries=$tries --sleep=$sleep --delay=$delay --daemon
directory=/var/app/current/
autostart=true
autorestart=true
numprocs=$numProcs
startsecs=$startSecs
user=webapp
environment=$environmentVal
```

After parsing all queues it then appends the programs to a clean `supervisord.conf` file in the same directory.

### 3. Deploy Supervisor

Now a bash script `02worker-deploy.sh` checks for `IS_WORKER=TRUE` in the EB environmental variables:

* If none is found the script does nothing and exits.
* If it is found
  * And there is no `supervisord.conf`
    * Supervisor is installed using pip
    * Configuration is parsed
    * Supervisor is set to start at boot
    * Supervisor is started
  * And there is an `supervisord.conf`
    * Supervisor is stopped
    * Configuration is parsed
    * Laravel artisan is used to restart the queue to refresh the daemon
    * Supervisor is restarted with the new configuration


# Caveats

Please check out issues and if you need them please make a PR!

## Contributing

Make a PR for some extra functionality and I will happily accept it :)

## License

This package is licensed under the [MIT license](https://github.com/FoxxMD/laravel-elasticbeanstalk-queue-worker/blob/master/LICENSE.txt).

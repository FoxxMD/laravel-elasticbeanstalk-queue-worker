# Laravel 5 Queue Worker for Elastic Beanstalk

*Use your L5 application as a worker to consume queues on AWS Elasticbeanstalk*

Laravel provides a [wonderful array](https://laravel.com/docs/5.1/queues) of drivers for consuming queues within your application as well as [some documentation](https://laravel.com/docs/5.1/queues#supervisor-configuration) on how to manage your application with [Supervisord](http://supervisord.org/) when it is acting as a worker.

Unfortunately that's where the documentation ends. There is no guidance on how to manage multiple workers from a devops context which is a huge bummer. But don't worry fam I've got your covered.

**This package enables your L5 application to manage itself, as a worker, in an [AWS Elasticbeanstalk](https://aws.amazon.com/elasticbeanstalk/) environment.**

**It provides these features:**

* **Automated installation of supervisor on first-time deployment**
* **Automatic updating of supervisor configuration upon deployment**
* **Two supervisor configuration deployment options:**
  * **Parsing of EB environmental variables to generate supervisor config**
  * **Or using a pre-built supervisor config supplied in project**

# Let's get down to business


## Installation

Require this package

```php
composer require "foxxmd/laravel-elasticbeanstalk-queue-worker"
```

**After installing the package you can either:**

Add the ServiceProvider to the providers array in `config/app.php`

```php
FoxxMD\LaravelElasticBeanstalkQueueWorker\ElasticBeanstalkQueueWorkerProvider::class
```

Then, publish using artisan

```php
php artisan vendor:publish --tag=ebworker
```

**OR**

Copy everything from `src/.ebextensions` into your own `.ebextensions` folder manually

**Note:** This library only consists of the EB deploy steps -- the provider is only for a convenience -- so if you want to you can modify/consolidate the `.ebextensions` folder if you're not into me overwriting your stuff.


## Configuration

### Enable Worker Mode

In order for worker deployment to be active you **must** add this environmental to your elasticbeanstalk environment configuration:

```
IS_WORKER = true
```

**If this variable is false or not present the deployment will not run**

### Set Queue Driver

Set the [driver](https://laravel.com/docs/5.1/queues#introduction) in your your EB envronmental variables:

```
QUEUE_DRIVER = [driver]
```

**Note: If no `QUEUE_DRIVER` key is present in your EB envronmental variables then `beanstalkd` will be used.**

### Add Queues

All queues are configured using EB envronmental variables with the following syntax:

**Note**: brackets are placeholders only, do not use them in your actual configuration

```
queue[QueueName]     = [queueName]   # Required. The name of the queue that should be run.
[QueueName]NumProcs  = [value]       # Optional. The number of instances supervisor should run for this queue. Defaults to 1
[QueueName]Tries     = [value]       # Optional. The number of times the worker should attempt to run in the event an unexpected exit code occurs. Defaults to 5
[QueueName]Sleep     = [value]       # Optional. The number of seconds the worker should sleep if no new jobs are in the queue. Defaults to 5
[QueueName]StartSecs = [value]       # Optional. How long a job should run for to be considered successful. Defaults to 1
[QueueName]Delay     = [value]       # Optional. Time in seconds a job should be delayed before returning to the ready queue. Defaults to 0
```

Add one `queue[QueueName] = [queueName]` entry in your EB environmental variables for each queue you want to run. The rest of the parameters are optional.

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

Note: you can check `eb-activity.log` for your EB environment to verify if the deploy process detected and deployed your file. Search for `Starting supervisor configuration parsing.` in the log.

# But how does it work?

Glad you asked. It's a simple process but required a ton of trial and error to get right (kudos to AWS for their lack of documentation)

EB applications can contain a [folder](https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/ebextensions.html) that provides advanced configuration for an EB environment, called `.ebextensions`.

This package uses AWS commands files in this folder to detect, install, and update supervisor and its configuration and then run it for you.

### 1. Ingress Supervisor rules

Supervisor requires port 9001 to be open if you want to access its web monitor. This is an optional step and can be removed if you don't need it by deleting `00supervisordIngress.config`

### 2. Parse Queue Configuration

`parseConfig.php` looks for either a user-supplied `supervisord.conf` file specified in configuration. If one exists then it is used.

Otherwise `parseConfig.php` looks for a json file generated earlier that contains all of the environmental variables configured for elastic beanstalk. It then parses out any queue configurations found (see `Add Queues`) section above and generates a supervisor program for each. The program to be generated looks like this:

```
[program:$queue]
command=sudo php artisan queue:work $connection --queue=$queue --tries=$tries --sleep=$sleep --daemon
directory=/var/app/current/
autostart=true
autorestart=true
process_name=$queue-%(process_num)s
numprocs=$numProcs
startsecs=$startSecs
```

After parsing all queues it then appends the programs to a clean `supervisord.conf` file in the same directory.

### 3. Deploy Supervisor

Now a bash script `workerDeploy.sh` checks for `IS_WORKER=TRUE` in the EB environmental variables:

* If none is found the script does nothing and exists.
* If it is found
  * And there is no `init.d` script
    * Supervisor is installed using pip and the custom `supervisord` init script in this project is copied to `/etc/init.d`
    * Configuration is parsed
    * Supervisor is started
    * Supervisor is set to start at boot
  * And there is an `init.d` script
    * Supervisor is stopped
    * Configuration is parsed
    * Laravel artisan is used to restart the queue to refresh the daemon
    * Supervisor is restarted with the new configuration


# Caveats

This is almost verbatim how I have things setup for another project so some usage is limited because of how it was originally written:

* Queue driver defaults to beanstalkd if not explicitly set
* There is no way to generate a supervisor program without `--queue=[queue]` right now

All of these are simple fixes though! Check out issues to see these and more and if you need them please make a PR!

## Contributing

Make a PR for some extra functionality and I will happily accept it :)

## License

This package is licensed under the [MIT license](https://github.com/FoxxMD/laravel-elasticbeanstalk-queue-worker/blob/master/LICENSE.txt).

<?php

return array(
	/*
	 * The path of the supervisord.conf file to be used INSTEAD OF generating one from environmental variables. Note that this can be null if you do not have one.
	 *
	 * This path is RELATIVE to the root of your application.
	 * EX:
	 * Absolute Path: /Users/dev/coding/MyProject/config/mysupervisord.conf
	 * Path you put in 'supervisorConfigPath': config/mysupervisord.conf
	 */
	'supervisorConfigPath' => null
);
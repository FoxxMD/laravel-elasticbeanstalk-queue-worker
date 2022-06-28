#!/usr/bin/env bash
# disables error related to needing a tty for sudo, allows running without cli
echo Defaults:root \!requiretty >> /etc/sudoers

# dump variables so we can find queue names to start in php script
/opt/elasticbeanstalk/bin/get-config environment > jsonEnv

# write supervisor program config to default conf
php .platform/queue-worker/parseConfig.php

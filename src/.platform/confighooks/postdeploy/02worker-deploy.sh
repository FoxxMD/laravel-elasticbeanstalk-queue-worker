#!/usr/bin/env bash
# Original Author:      Matt Foxx Duncan <matt.duncan13@gmail.com>
#
# Description: For use with AWS elasticbeanstalk and Laravel. As part of a deployment will either skip, install,
#              or update supervisord depending on environmental variables set in EB.
#              This script has been modified as necessary to support Amazon Linux 2023

updateSupervisor(){
    cp .platform/queue-worker/supervisord.conf /etc/supervisord.conf
    systemctl stop supervisord
    php /var/app/current/artisan queue:restart # If this worker is running in daemon mode (most likely) we need to restart it with the new build
    echo "Sleeping a few seconds to make sure supervisor shuts down..." # https://github.com/Supervisor/supervisor/issues/48#issuecomment-2684400
    sleep 5
    systemctl start supervisord
}

installSupervisor(){
    dnf install python3-pip -y
    pip install supervisor
    mkdir -p /var/log/supervisor
    mkdir -p /var/run/supervisor
    cp .platform/queue-worker/supervisord.service /usr/lib/systemd/system/supervisord.service
    cp .platform/queue-worker/supervisord.conf /etc/supervisord.conf
    systemctl daemon-reload
    systemctl enable supervisord
    systemctl start supervisord
}

/opt/elasticbeanstalk/bin/get-config --output YAML environment | sed -e 's/^\(.*\): /\1=/g' > bashEnv

declare -A ary

readarray -t lines < "bashEnv"

for line in "${lines[@]}"; do
    key=${line%%=*}
    value=${line#*=}
    ary[$key]=$value
done

#if key exists and is true

if test "${ary['IS_WORKER']+isset}" #if key exists
then
    if [ ${ary['IS_WORKER']} == '"true"' ] #if the value is true
    then
        echo 'Found worker key!';
        echo 'Starting worker deploy process...';

        if [ -f /etc/supervisord.conf ]
        then
           echo 'Config found. Supervisor already installed';
           updateSupervisor;
        else
           echo "No supervisor config found. Installing supervisor...";
           installSupervisor;
        fi;

        echo 'Deployment done!';

    else
        echo 'Worker variable set, but not true. Skipping worker installation';
    fi;

else
    echo 'No worker variable found. Skipping worker installation';
fi;

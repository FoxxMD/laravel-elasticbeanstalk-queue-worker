#!/usr/bin/env bash
# Original Author:      Matt Foxx Duncan <matt.duncan13@gmail.com>
#
# Description: For use with AWS elasticbeanstalk and Laravel. As part of a deployment will either skip, install,
#              or update supervisord depending on environmental variables set in EB.
#              This script has been modified as necessary to support Amazon Linux 2

updateSupervisor(){
    cp .ebextensions/queue-worker/supervisord.conf /etc/supervisord.conf
    sudo service supervisord stop
    php /var/app/current/artisan queue:restart # If this worker is running in daemon mode (most likely) we need to restart it with the new build
    echo "Sleeping a few seconds to make sure supervisor shuts down..." # https://github.com/Supervisor/supervisor/issues/48#issuecomment-2684400
    sleep 5
    sudo service supervisord start
}

installSupervisor(){
    pip install --install-option="--install-scripts=/usr/bin" supervisor --pre
    cp /var/app/current/.ebextensions/queue-worker/supervisord /etc/init.d/supervisord
    chmod 777 /etc/init.d/supervisord
    mkdir -m 766 /var/log/supervisor
    umask 022
    touch /var/log/supervisor/supervisord.log
    cp .ebextensions/queue-worker/supervisord.conf /etc/supervisord.conf
    /etc/init.d/supervisord  start
    sudo chkconfig supervisord  on
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
        if [ ${ary['IS_WORKER']} == "'true'" ] #if the value is true
            then
                echo "Found worker key!";
                echo "Starting worker deploy process...";

                if [ -f /etc/init.d/supervisord ]
                    then
                       echo "Config found. Supervisor already installed";
                       updateSupervisor;
                    else
                       echo "No supervisor config found. Installing supervisor...";
                       installSupervisor;
                fi;

                echo "Deployment done!";

            else
                echo "Worker variable set, but not true. Skipping worker installation";
        fi;

    else
        echo "No worker variable found. Skipping worker installation";
fi;

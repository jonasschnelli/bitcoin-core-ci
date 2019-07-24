#!/bin/bash

set -x
set -e
USER=ubuntu

. ./shared.sh

if [ $(vm_is_running $1) == "no" ]; then
  output "vm not running"
  exit 0
fi

IP=`./getguestipv4.sh $1`
SSHHOST="${USER}@${IP}"
wait_for_ssh $SSHHOST
ssh -oStrictHostKeyChecking=no $SSHHOST
 
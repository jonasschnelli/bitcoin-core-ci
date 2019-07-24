#!/bin/bash

set -e
set -x

. ./shared.sh

BASEVM=${1:-ubuntu1804_base}
WORKER_NR=${2:-1}
SRC=${3:-result.tar.gz}
DEST=${4:-result.tar.gz}

WORKER="${BASEVM}_${WORKER_NR}"

if [ $(vm_is_running ${WORKER}) == "no" ]; then
  output "vm not running"
  exit 0
fi

IP=$(get_vm_ip $WORKER)
SSHHOST="ubuntu@${IP}"
wait_for_ssh $SSHHOST
scp -oStrictHostKeyChecking=no $SSHHOST:~/${SRC} ${DEST}

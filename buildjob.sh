#!/bin/bash

set -e
set -x

. ./shared.sh

BASEVM=${1:-ubuntu1804_base}
WORKER_NR=${2:-1}
UUID=${3:-undef}
VMLOGPATH="/mnt/shared/logs/builder_${WORKER_NR}_${UUID}.log"
WORKER="${BASEVM}_${WORKER_NR}"

# read the env variable script from stdin
PIPE_IN=""
if [ -p /dev/stdin ]; then
        while IFS= read line; do
                PIPE_IN="${PIPE_IN}\n${line}"
        done
fi

# we assume that the caller made sure the VM is not in use for another build
# shutdown the VM if running (hard shutdown)
vm_hard_shutdown $WORKER
# revert the base snapshot
virsh snapshot-revert --domain $WORKER --snapshotname base
# start the VM
virsh start $WORKER

# get the IP (should be a fixed IP anyways, TODO: check if switching to a fix WORKER-NR->IP table makse sense)
IP=$(get_vm_ip $WORKER)
SSHHOST="ubuntu@${IP}"
# check if we need to delete an entry from known host
check_delete_known_host $IP
# wait until SSH is available
wait_for_ssh $SSHHOST

# echo back the ENV script
echo -e "$PIPE_IN"

# send the ENV script to the host
echo -e "$PIPE_IN" | ssh -oStrictHostKeyChecking=no $SSHHOST -T "cat > ~/env.sh"

# copy all the guest scripts
# TODO: if the yml file is comming from GIT, the guest scripts could as well
scp -oStrictHostKeyChecking=no guest_scripts/* $SSHHOST:~/

# set uuid-file (for the ease of debug)
ssh -oStrictHostKeyChecking=no $SSHHOST "echo $UUID | cat > uuid"

# set hostname
ssh -oStrictHostKeyChecking=no $SSHHOST "sudo hostnamectl set-hostname ${WORKER}"

# make sure we have mounted the NFS share
echo "Mounting NFS..."
ssh -oStrictHostKeyChecking=no $SSHHOST "sudo mount /mnt/shared"
echo "testing NFS..."
# TODO: switch to a REAL test ;)
ssh -oStrictHostKeyChecking=no $SSHHOST "ls -la /mnt/shared/"
echo "nfs okay"

# start build by launching GNU screen and redirect the logfile to the NFS share
echo "starting build"
ssh -oStrictHostKeyChecking=no $SSHHOST "screen -L -Logfile ${VMLOGPATH} -d -m -S build -h 100000 bash -c /home/ubuntu/guest_run.sh"
echo "build stared"

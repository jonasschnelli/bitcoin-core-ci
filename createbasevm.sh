#!/bin/bash

set -e
set -x

. ./shared.sh

BASEIMG=ubuntu1804
VMNAME=$BASEIMG"_base"
USERNAME=ubuntu
VMCPU=2
VMRAM=4098
VMDISK=12
BASEPACKAGES="python3 python3-zmq build-essential \
libtool autotools-dev automake pkg-config \
bsdmainutils curl git ca-certificates ccache joe \
"
#nsis g++-mingw-w64-x86-64 wine-binfmt wine64 \
#g++-multilib \
#xorg"

if [ ! -d kvm-install-vm ]; then
  git clone https://github.com/jonasschnelli/kvm-install-vm
fi

kvm-install-vm/kvm-install-vm create -t $BASEIMG -u $USERNAME -d $VMDISK -c $VMCPU -m $VMRAM -v -g vnc $VMNAME || true

IP=$(get_vm_ip $VMNAME)
echo $IP

#wait for sshd
sleep 10

#fix static MAC address issue that would prevent network from working in clones

if [ $BASEIMG == "ubuntu1804" ]; then
  scp -oStrictHostKeyChecking=no guest_scripts/guest_fix_netplan.sh ubuntu@$IP:/tmp
  ssh -oStrictHostKeyChecking=no ubuntu@$IP '/tmp/guest_fix_netplan.sh'
fi

#set password for ubuntu user
ssh -oStrictHostKeyChecking=no ubuntu@$IP 'echo -e "ubuntu\nubuntu" | sudo passwd ubuntu'

ssh -oStrictHostKeyChecking=no ubuntu@$IP 'echo "APT::Acquire::Retries \"5\";" | sudo tee -a /etc/apt/apt.conf.d/80-retries'

#install base stuff
ssh -oStrictHostKeyChecking=no ubuntu@$IP "sudo apt-get -qq -y update && sudo apt-get -qq -y install ${BASEPACKAGES} && sudo mount -a"

ssh -oStrictHostKeyChecking=no ubuntu@$IP 'echo systemd hold | sudo dpkg --set-selections && sudo apt-get -y -qq full-upgrade'

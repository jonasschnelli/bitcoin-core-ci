#!/bin/bash
INTERFACE=`awk '/set-name: /{print $NF}' /etc/netplan/50-cloud-init.yaml`
cat > /tmp/50-cloud-init.yaml <<- EOM
# This file is generated from information provided by
# the datasource.  Changes to it will not persist across an instance.
# To disable cloud-init's network configuration capabilities, write a file
# /etc/cloud/cloud.cfg.d/99-disable-network-config.cfg with the following:
# network: {config: disabled}
network:
    ethernets:
        $INTERFACE:
            dhcp4: true
            dhcp-identifier: mac
    version: 2
EOM

sudo cp /tmp/50-cloud-init.yaml /etc/netplan/50-cloud-init.yaml

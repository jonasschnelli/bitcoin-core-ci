#!/bin/bash

set -e
set -x

. ./shared.sh


NAME=$1
BRIDGE=virbr0

get_vm_ip $1
#
#MAC=`virsh dumpxml $NAME | grep "mac address" | awk -F\' '{ print $2}'`
##while true
#        do
#            IP=$(grep -B1 $MAC /var/lib/libvirt/dnsmasq/$BRIDGE.status | head \
#                 -n 1 | awk '{print $2}' | sed -e s/\"//g -e s/,//)
#            if [ "$IP" = "" ]
#            then
#                sleep 1
#            else
#                
#                break
#            fi
#        done
#echo $IP
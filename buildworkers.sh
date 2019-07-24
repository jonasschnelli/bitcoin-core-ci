#!/bin/bash

set -e
set -x

. ./shared.sh

BASEVM=ubuntu1804_base
vm_graceful_shutdown $BASEVM
MAC_BASE="52:54:00:00:e6:0"
for i in {1..8}
do
  CLONENAME="${BASEVM}_$i"
  MACADDR=${MAC_BASE}$i
  vm_graceful_shutdown $CLONENAME
  vm_delete $CLONENAME
  virt-clone --original $BASEVM --name $CLONENAME --auto-clone -m $MACADDR
  virsh snapshot-create-as --domain $CLONENAME --name "base"
done
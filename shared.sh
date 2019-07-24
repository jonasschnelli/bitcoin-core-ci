#!/bin/bash

bold() { echo -e "\e[1m$@\e[0m" ; }
red() { echo -e "\e[31m$@\e[0m" ; }
green() { echo -e "\e[32m$@\e[0m" ; }
yellow() { echo -e "\e[33m$@\e[0m" ; }

ok() { green "${@:-OK}" ; }

output() { echo -e "- $@" ; }
outputn() { echo -en "- $@ ... " ; }

function is_ssh_running ()
{
	ssh -oStrictHostKeyChecking=no -q $1 exit
	if [ $? -ne 0 ]; then
		echo "no"
	else
		echo "yes"
	fi
}

function wait_for_ssh ()
{
	counter=0
	timeout=120
	while ([ $(is_ssh_running $1) == "no" ] && [ "$counter" -lt "$timeout" ])
	do
		sleep 2
		counter=$((counter+1))
	done
	if [ "$counter" -eq "$timeout" ]; then
		output "ERROR: SSH check timout... SSH is still down"
		exit 1
	fi
	output "SSH up"
}

function vm_exists ()
{
tmp=$(virsh list --all | grep " $1 " | awk '{ print $2}')
if [ "x$tmp" == "x$1" ]; then
	echo "yes"
else
	echo "no"
fi
}

function vm_is_running ()
{
tmp=$(virsh list --all | grep " $1 " | awk '{ print $3}')
if ([ "x$tmp" == "x" ] || [ "x$tmp" != "xrunning" ]); then
	echo "no"
else
	echo "yes"
fi
}

function vm_delete ()
{
	if [ $(vm_is_running $1) == "yes" ]; then
		vm_graceful_shutdown $1 yes
	else
		if [ $(vm_exists $1) == "yes" ]; then
			virsh undefine --remove-all-storage --snapshots-metadata $1
			sleep 1
		fi
	fi
}

function vm_hard_shutdown ()
{
	if [ x$(vm_is_running $1) == "xyes" ]; then
		output "vm is running, shutting down..."
		virsh destroy $1
		while [ x$(vm_is_running $1) == "xyes" ]
		do
			sleep 1
		done
		if ([ -z $2 ] && [ x$2 == "xyes" ]); then
			virsh undefine --remove-all-storage --snapshots-metadata $1
			sleep 1
		fi
	fi
}

function vm_graceful_shutdown ()
{
	if [ x$(vm_is_running $1) == "xyes" ]; then
		output "vm is running, shutting down..."
		virsh shutdown $1
		while [ x$(vm_is_running $1) == "xyes" ]
		do
			sleep 1
		done
		if ([ -z $2 ] && [ x$2 == "xyes" ]); then
			virsh undefine --remove-all-storage --snapshots-metadata $1
			sleep 1
		fi
	fi
}

function check_delete_known_host ()
{
   output "Checking for $1 in known_hosts file"
    grep -q $1 ${HOME}/.ssh/known_hosts \
        && outputn "Found entry for $1. Removing" \
        && (sed --in-place "/^$1/d" ~/.ssh/known_hosts && ok ) \
        || output "No entries found for $1"
}

function get_vm_ip_old ()
{
NAME=$1
BRIDGE=virbr0
MAC=`virsh dumpxml $NAME | grep "mac address" | awk -F\' '{ print $2}'`
while true
        do
            IP=$(grep -B1 $MAC /var/lib/libvirt/dnsmasq/$BRIDGE.status | head \
                 -n 1 | awk '{print $2}' | sed -e s/\"//g -e s/,//)
            if [ "$IP" = "" ]
            then
                sleep 1
            else

                break
            fi
        done
echo $IP
}

function get_vm_ip ()
{
NAME=$1
BRIDGE=virbr0
MAC=`virsh dumpxml $NAME | grep "mac address" | awk -F\' '{ print $2}'`
while true
        do
            IP=$(arp -an | grep $MAC | awk '{ print $2}' | sed 's/[\(\)]//g')
            if [ "$IP" = "" ]
            then
                sleep 1
            else

                break
            fi
        done
echo $IP
}

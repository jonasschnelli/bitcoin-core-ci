#!/bin/bash

set -x

. ./env.sh

# try to apt get for a couple of times
cnt=0
if [ -n "$ADDARCH" ]; then
  sudo dpkg --add-architecture $ADDARCH
  while true; do
		sudo apt update -qq -y
		if [ $? -eq 0 ]; then
		  break
		fi
		cnt=$((cnt+1))
		if [ $cnt -eq 60 ]; then
			echo "apt failed after 30 retries... erroring"
			exit 1
		fi
		sleep 3
	done
fi
cnt=0
while true; do
	sudo apt-get install -qq -y ${PACKAGES}
	if [ $? -eq 0 ]; then
		break
	fi
	cnt=$((cnt+1))
	if [ $cnt -eq 60 ]; then
		echo "apt failed after 30 retries... erroring"
		exit 1
	fi
	sleep 3
done
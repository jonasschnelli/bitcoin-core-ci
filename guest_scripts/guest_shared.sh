#!/bin/bash
set +x

timeblock_start() {
	echo -e "\n###START#$1#$(date +%s)\n"
}

timeblock_end() {
	echo -e "\n###END#$1#$(date +%s)\n\n\n"
}
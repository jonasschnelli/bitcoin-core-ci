#!/bin/bash

. ./env.sh
. ./guest_shared.sh

timeblock_start GIT_CHECKOUT
./guest_00_checkout.sh
timeblock_end GIT_CHECKOUT

timeblock_start APT_INSTALL
./guest_00_install.sh
timeblock_end APT_INSTALL

./guest_01_compile.sh
EXIT_CODE=$?
echo "Build finished with exit code ${EXIT_CODE}"
echo "#BUILD#${JOB_UUID}#: ${EXIT_CODE}"

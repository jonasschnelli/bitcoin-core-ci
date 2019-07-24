#!/bin/bash

set -x
set -e
. ./shared.sh
vm_graceful_shutdown $1

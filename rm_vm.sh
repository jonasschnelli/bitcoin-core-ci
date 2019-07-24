#!/bin/bash

set -x
set -e
. ./shared.sh
vm_delete $1

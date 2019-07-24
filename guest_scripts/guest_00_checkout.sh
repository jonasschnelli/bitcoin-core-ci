#!/bin/bash

set -x
set -e

. ./env.sh

if [[ ! -z $GIT_REPOSITORY ]]; then
  git clone -q --depth=50 $GIT_REPOSITORY src
  cd src
  if [[ ! -z $GIT_BRANCH ]]; then
    git fetch -q origin +$GIT_BRANCH
    git checkout -qf FETCH_HEAD
  fi
  if [[ ! -z $GIT_COMMIT ]]; then
    git checkout -qf $GIT_COMMIT
  fi
fi
cd src
GITHEAD=`git rev-parse HEAD`
echo "###GITHEAD#${GITHEAD}"
cd ..
#!/bin/bash

export MAKEJOBS=-j4
export HOST=x86_64-unknown-linux-gnu
export BITCOIN_CONFIG="--enable-zmq --with-gui=qt5 --enable-glibc-back-compat --enable-reduce-exports --enable-debug"
export GOAL="install"

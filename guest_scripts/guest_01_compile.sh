#!/bin/bash

set -x
set -e

. ./env.sh
. ./guest_shared.sh

ccache -s

echo "compile..."
echo ""

HOMEPATH="/home/ubuntu"
BASEPATH="${HOMEPATH}/src"

# switch compiler
if [[ $HOST = *-mingw32 ]]; then
  sudo update-alternatives --set $HOST-g++ $(which $HOST-g++-posix)
fi

# restore depends cache
timeblock_start "RESTORE_CACHE"
cd $BASEPATH
mkdir -p depends/built
COPYOVER=no
COPYOVER_CCACHE=no
rm -rf ${HOMEPATH}/.ccache
GIT_BRANCH_LC=`echo $GIT_BRANCH | tr '[:upper:]' '[:lower:]'`
if [[ ! -z $GIT_BRANCH ]] && [ $GIT_BRANCH_LC != "master" ]; then
	GIT_BRANCH_MD5=`echo $GIT_BRANCH_LC | md5sum | cut -f1 -d" "`
	if [ -d "/mnt/shared/cache/built/${GIT_BRANCH_MD5}_${HOST}" ]; then
		cp -r "/mnt/shared/cache/built/${GIT_BRANCH_MD5}_${HOST}" depends/built/${HOST}
		COPYOVER=yes
	fi
	if [ -f "/mnt/shared/cache/${GIT_BRANCH_MD5}_ccache_${HOST}.tar" ]; then
		cp "/mnt/shared/cache/${GIT_BRANCH_MD5}_ccache_${HOST}.tar" ${HOMEPATH}/ccache.tar
		tar -xf ${HOMEPATH}/ccache.tar -C ${HOMEPATH}/
		COPYOVER_CCACHE=yes
	fi
fi

# copy master cache depends if no branch cache has been found
if [ $COPYOVER == "no" ] && [ -d /mnt/shared/cache/built/master_$HOST ]; then
	cp -r /mnt/shared/cache/built/master_$HOST depends/built/$HOST
fi

if [ $COPYOVER_CCACHE == "no" ] && [ -f /mnt/shared/cache/master_ccache_$HOST.tar ]; then
	cp -r /mnt/shared/cache/master_ccache_$HOST.tar ${HOMEPATH}/ccache.tar
	tar -xf ${HOMEPATH}/ccache.tar -C ${HOMEPATH}/
fi

ccache -s
timeblock_end "RESTORE_CACHE"

OUTDIR="$BASEPATH/out"
mkdir -p $OUTDIR

time ./autogen.sh


mkdir -p depends/SDKs depends/sdk-sources

if [ -n "$OSX_SDK" ] && [ ! -f depends/sdk-sources/MacOSX${OSX_SDK}.sdk.tar.gz ] && [ -f /mnt/shared/cache/MacOSX${OSX_SDK}.sdk.tar.gz ]; then
  cp /mnt/shared/cache/MacOSX${OSX_SDK}.sdk.tar.gz depends/sdk-sources/MacOSX${OSX_SDK}.sdk.tar.gz
fi
if [ -n "$OSX_SDK" ] && [ -f depends/sdk-sources/MacOSX${OSX_SDK}.sdk.tar.gz ]; then
  tar -C depends/SDKs -xf depends/sdk-sources/MacOSX${OSX_SDK}.sdk.tar.gz
fi


timeblock_start "MAKE_DEPENDS"
cd depends
make HOST=$HOST $MAKEJOBS
timeblock_end "MAKE_DEPENDS"

timeblock_start "UPDATE_DEPENDENCY_CACHE"
mkdir -p /mnt/shared/cache/built
STORENAME=$GIT_BRANCH_LC
if [[ ! -z $GIT_BRANCH ]] && [ $GIT_BRANCH_LC != "master" ]; then
	STORENAME=`echo $GIT_BRANCH_LC | md5sum | cut -f1 -d" "`
fi
cp -rf built/${HOST} /mnt/shared/cache/built/${STORENAME}_copy_${HOST}
rm -rf /mnt/shared/cache/built/${STORENAME}_${HOST}
mv /mnt/shared/cache/built/${STORENAME}_copy_${HOST} /mnt/shared/cache/built/${STORENAME}_${HOST}
cd ..
timeblock_end "UPDATE_DEPENDENCY_CACHE"

timeblock_start "CONFIGURE"
time ./configure --disable-dependency-tracking --prefix=$BASEPATH/depends/$HOST --bindir=$OUTDIR/bin --libdir=$OUTDIR/lib $BITCOIN_CONFIG
timeblock_end "CONFIGURE"

timeblock_start "COMPILE_AND_INSTALL"
time make $GOAL $MAKEJOBS
ccache -s
timeblock_end "COMPILE_AND_INSTALL"

timeblock_start "UPDATE_CCACHE_CACHE"
time tar -cf ${HOMEPATH}/ccache.tar -C ${HOMEPATH}/ .ccache
time cp -rf ${HOMEPATH}/ccache.tar /mnt/shared/cache/${STORENAME}_ccache_copy_${HOST}.tar
if [ -f "/mnt/shared/cache/${STORENAME}_ccache_${HOST}.tar" ]; then
	time mv /mnt/shared/cache/${STORENAME}_ccache_${HOST}.tar /mnt/shared/cache/DEL_${STORENAME}_ccache_${HOST}_${JOB_UUID}
fi
time mv /mnt/shared/cache/${STORENAME}_ccache_copy_${HOST}.tar /mnt/shared/cache/${STORENAME}_ccache_${HOST}.tar
timeblock_end "UPDATE_CCACHE_CACHE"

timeblock_start "RUN_TESTS"

if [ "$RUN_UNIT_TESTS" = "true" ]; then
  time make $MAKEJOBS check VERBOSE=1
fi

if [ "$RUN_FUNCTIONAL_TESTS" = "true" ]; then
  time test/functional/test_runner.py --ci --combinedlogslen=4000 --quiet --failfast
fi
timeblock_end "RUN_TESTS"

if [ -n "$OSX_SDK" ]; then
  make install
  if [ -f ${BASEPATH}/Bitcoin-Core.dmg ]; then
    cp -rf ${BASEPATH}/Bitcoin-Core.dmg ${OUTDIR}
  fi
fi

tar -czf ${BASEPATH}/out.tar.gz ${OUTDIR}

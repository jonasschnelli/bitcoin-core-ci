Bitcoin Core CI
=====================================

https://bitcoinbuilds.org

Bitcoin Core CI is a set of scripts to provide a CI (continious integration) with web frontend and GitHub integration.

### How does it work
Under the hood, it's using KVM/virsh to start a clean VM where the build happens.
The build runs in GNU screen (in the VM) and redirect the console output to a NFS share.

In the center, there is a daemon written in python (buildserver.py), running on the host,
that checks the sqlite3 database for new work.

The daemon decomposes build requests into jobs by looking at the yml configuration file.

Jobs are tracked by parsing the log file in the NFS share produced by the VM's GNU screen.

### Status

The code is still messy and buggy.
Your help is wanted!
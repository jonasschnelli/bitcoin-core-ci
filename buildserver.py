#!/bin/env python3

import sqlite3
import time
import yaml
import re
import uuid
import subprocess
import os
import threading
from threading import Thread
from enum import IntEnum 

SQLLITE_DB        = "builds.sqlite"
SCRIPT_DIR        = os.path.dirname(__file__)
DATA_DIR          = os.path.join(SCRIPT_DIR, "data")
LOGSDIR_WORK      = "/home/jonasschnelli/vmshared/logs/"
LOGSDIR_FINAL     = os.path.join(SCRIPT_DIR, "logs")
MAX_STALL_TIMEOUT = 300
GLOBAL_ENV        = "#!/bin/bash\n"
RUNLOOP_SLEEP     = 3

class BuildState(IntEnum):
    new = 0
    starting = 1
    started = 2
    failed = 3
    stalled = 4
    success = 5
    canceled = 6

    def ended(self) -> bool:
        return True if self in [BuildState.failed, BuildState.stalled, 
                                BuildState.success, BuildState.canceled] else False

sql_con = sqlite3.connect(os.path.join(DATA_DIR, SQLLITE_DB))
cur = sql_con.cursor()
sql_con.commit()
sql_con.set_trace_callback(print)

# create a job in the database
def sql_create_job(conn, project):
    sql = ''' INSERT INTO jobs(to_build, uuid, name, starttime, endtime, baseimage, shellscript)
              VALUES(?,?,?,?,?,?,?) '''
    cur = conn.cursor()
    cur.execute(sql, project)
    conn.commit()
    return cur.lastrowid

# create all jobs from a build by loading/processing the YAML
def create_jobs_from_build(conn, row):
    global GLOBAL_ENV

    #read the build from DB
    cur = sql_con.cursor()
    cur.execute("UPDATE builds SET state=?, starttime=? WHERE rowid=?", [BuildState.started, int(time.time()), row['rowid']])
    sql_con.commit()

    #TODO: change static yml by checking out the GIT one and looking at the override link in the database
    yml_content = ""
    with open('default.yml', 'r') as content_file:
        yml_content = content_file.read()

    # add the git basics to the env script
    job_settings = GLOBAL_ENV
    job_settings += "GIT_REPOSITORY=\""
    if row['repo'] is not None: job_settings += row['repo']
    job_settings += "\"\n"

    job_settings += "GIT_BRANCH=\""
    if row['branch'] is not None: job_settings += row['branch']
    job_settings += "\"\n"
    
    job_settings += "GIT_COMMIT=\""
    if row['commit'] is not None: job_settings += row['commit']
    job_settings += "\"\n"

    try:
        # load YAML
        settings = yaml.safe_load(yml_content)

        # load global settings
        for env in settings['env']['global']:
            job_settings += "export "+env+"\n"

        # process jobs
        for job in settings['jobs']:
            # define a job uuid
            job_uuid = str(uuid.uuid4())
            # process env vars
            variables = re.findall(r'([\w]+)=(\"([^\"]*)\"|[^ ]*)', job['env'])
            jobenv = job_settings
            jobenv += "JOB_UUID=\""+job_uuid+"\"\n"
            for var in variables:
                jobenv += var[0]+"="+var[1]+"\n"

            #TODO: move to debug function
            print("=="+job['name'])
            print(jobenv)
            print("-----------")
            # create db
            sql_create_job(conn, (row['rowid'], job_uuid, job['name'], 0, 0, row['image'], jobenv))

    except yaml.YAMLError as exc:
        print(exc)

# find new builds and create the jobs database entries
def process_builds(conn):
    c = conn.cursor()
    c.row_factory = sqlite3.Row
    # query builds that have not yet been processed
    # TODO: check if starttime is the right field, process multiple builds (threaded)
    c.execute("SELECT rowid, * FROM builds WHERE starttime=0")
    row = c.fetchone()
    if row:
        print(row)
        create_jobs_from_build(sql_con, row)

# check if vm is running (via shall script)
# TODO: currently unused, we completely trust the database
def is_vm_running(name):
    proc = subprocess.Popen([os.path.join(SCRIPT_DIR, "is_vm_running.sh"), name])
    stdout = proc.communicate()[0]
    rc = proc.returncode
    if rc == 0:
        return True
    return False

# get last edit time of a file
def last_edit(filename):
        return 0
    st=os.stat(filename)    
    return st.st_mtime
    if not os.path.isfile(filename):

# check if build has stalled
def build_has_stalled(starttime, uuid, logfile):
    if not os.path.isfile(logfile) and (time.time() - starttime) > MAX_STALL_TIMEOUT:
        with open(logfile, "w") as myfile:
            myfile.write("Build has stalled, logfile not written for "+str(time.time() - starttime)+" time")
        return True

    if not os.path.isfile(logfile):
        return False

    lastline = ""
    try:
        lastline = subprocess.check_output(['tail', '-1', logfile]).decode()
    except UnicodeError as exc:
        pass
    if lastline.startswith("#BUILD#"+uuid+"#: "):
        return False

    last_edit_time = last_edit(logfile)
    if time.time() - last_edit_time > MAX_STALL_TIMEOUT:
        st=os.stat(logfile)
        with open(logfile, "a") as myfile:
            myfile.write("Build has stalled, no output for more then "+str(time.time() - last_edit_time)+" seconds, logfile:"+logfile+", time:"+str(st.st_mtime))
        return True
    return False

# get last lines of a file (returns empty string if file does not exists or decode fails)
def last_log_lines(logfile):
    if not os.path.isfile(logfile):
        return ""
    lastlines = ""
    try:
        lastlines = subprocess.check_output(['tail', '-5', logfile]).decode()
    except UnicodeError as exc:
        pass
    return lastlines

# get last lines of a file (returns empty string if file does not exists or decode fails)
def get_log_times(logfile):
    if not os.path.isfile(logfile):
        return ""
    times = ""
    try:
        times = subprocess.check_output(['grep', '^###', logfile], stderr=subprocess.STDOUT).decode()
    except UnicodeError as exc:
        pass
    except subprocess.CalledProcessError as e:
        pass
    return times

# check if build as been completed
def build_is_completed(starttime, uuid, logfile):
    if build_has_stalled(starttime, uuid, logfile) == True:
        return BuildState.stalled

    if not os.path.isfile(logfile):
        return BuildState.new

    lastline = ""
    try:
        lastline = subprocess.check_output(['tail', '-1', logfile]).decode()
    except UnicodeError as exc:
        pass
    pattern = "#BUILD#"+uuid+"#: "
    if lastline.startswith(pattern):
        retcode = lastline[len(pattern):len(pattern)+1]
        if int(retcode) == 0:
            return BuildState.success
        else:
            # any other non null return code equals fail
            return BuildState.failed
    return BuildState.started

# find a idling worker
def find_free_worker(conn, baseimage):
    # TODO: make worker amount configurable
    for i in range(1,7):
        c = conn.cursor()
        c.execute("SELECT rowid, * FROM jobs WHERE workernr=? AND starttime!=0 AND endtime=0", [i])
        row = c.fetchone()
        if row:
            continue #worker in use
        return i
    return 0 # means no worker is free

build_threads = [] # global list of threads (in order to process on completion)

# thread class that handles starting a build via shell script
class StartBuildThread(Thread):
    def __init__(self, baseimage, workernr, uuid, shellscript, jobid):
        ''' Constructor. '''
 
        Thread.__init__(self)
        # keep data
        self.baseimage = baseimage
        self.workernr = workernr
        self.uuid = uuid
        self.shellscript = shellscript
        self.jobid = jobid
        self.returncode = -1

    def get_returncode(self):
        return self.returncode
    def get_jobid(self):
        return self.jobid
    def run(self):
        print("call subprocess")
        proc = subprocess.Popen([os.path.join(SCRIPT_DIR, "buildjob.sh"), self.baseimage, self.workernr, self.uuid], stdin=subprocess.PIPE)
        stdout = proc.communicate(input=self.shellscript)[0]
        print("comms done")
        self.returncode = proc.returncode
        print("Returncode: "+str(self.returncode))

# process jobs, update status, detect stalles and complete builds
def process_jobs(conn):
    global LOGSDIR_WORK
    global MAX_STALL_TIMEOUT
    global LOGSDIR_FINAL
    global build_threads
    c = conn.cursor()
    c.row_factory = sqlite3.Row
    c.execute("SELECT rowid, * FROM jobs WHERE starttime=0")

    # fetch all new jobs
    while True:
        rows = c.fetchmany(1000)
        if not rows: break
        for r in rows:
            workernr = find_free_worker(sql_con, r['baseimage'])
            if workernr == 0:
                print("No free worker")
                continue

            # make sure we register that we are trying to start the jobs (state "starting")
            cur = sql_con.cursor()
            cur.execute("UPDATE jobs SET workernr=?, state=?, starttime=? WHERE rowid=?", [workernr, BuildState.starting, int(time.time()), r['rowid']])
            sql_con.commit()

            # start the job in thread
            thread = StartBuildThread(r['baseimage'], str(workernr), r['uuid'], r['shellscript'].encode(), r['rowid'])
            build_threads.append(thread)
            thread.start()

            # create a symlink for the work log file
            logfile = os.path.join(LOGSDIR_WORK, "builder_"+str(workernr)+"_"+r['uuid']+".log")
            log_final = os.path.join(LOGSDIR_FINAL, r['uuid']+".log")
            os.symlink(logfile, log_final)

    # check running builds
    c.execute("SELECT rowid, * FROM jobs WHERE starttime!=0 AND endtime=0")
    while True:
        rows = c.fetchmany(1000)
        if not rows: break
        for r in rows:
            logfile = os.path.join(LOGSDIR_WORK, "builder_"+str(r['workernr'])+"_"+r['uuid']+".log")

            # figure out what task is currently in work
            taskname = "unknown"
            tasktime = 0
            times = get_log_times(logfile)
            lasttimes = times.splitlines()
            if len(lasttimes) >= 1:
                matches = re.match(r'^###([^#]*)#([^#]*)#([0-9]*)', lasttimes[-1])
                if matches:
                    tasktime = int(time.time())-int(matches.groups()[2])
                    taskname = matches.groups()[1]
            if r['task'] != taskname:
                # update the job's current task if different
                cur = sql_con.cursor()
                cur.execute("UPDATE jobs SET task=?, alltasks=? WHERE rowid=?", [taskname, times, r['rowid']])
                sql_con.commit()
            
            state = build_is_completed(r['starttime'], r['uuid'], logfile)
            if r['action'] == 1:
                # cancle build
                print("canceling job...")
                state = BuildState.canceled
            if state.ended():
                print("ending build")
                job_endtime = last_edit(logfile)
                log_final = os.path.join(LOGSDIR_FINAL, r['uuid']+".log")
                if job_endtime == 0:
                    # if we could not get a job end time, assume "now" is the time the build has ended
                    job_endtime = time.time()
                else:
                    # file exists, copy logfile
                    os.remove(log_final) # remove symlink
                    os.rename(logfile, log_final)
                    if state == BuildState.stalled:
                        with open(log_final, "a") as myfile:
                            myfile.write("Build has stalled, no output for more then "+str(MAX_STALL_TIMEOUT)+" seconds")

                #copy build results
                # TODO: use ~/out.tar.gz and make sure it won't stop the script if the file is not present
                try:
                    subprocess.check_output([os.path.join(SCRIPT_DIR, "fetchvmfile.sh"), r['baseimage'], str(r['workernr']), "src/out.tar.gz", os.path.join(LOGSDIR_FINAL, r['uuid']+"-buildresult.tar.gz")], stderr=subprocess.STDOUT).decode()
                except UnicodeError as exc:
                    print("unicode error")
                    pass
                except subprocess.CalledProcessError as e:
                    print("command '{}' return with error (code {}): {}".format(e.cmd, e.returncode, e.output))
                    pass
                
                # shutdown the VM
                subprocess.check_output([os.path.join(SCRIPT_DIR, "shutdown_vm.sh"), r['baseimage']+"_"+str(r['workernr'])])

                # update the job (has ended)
                cur = sql_con.cursor()
                times = get_log_times(log_final)
                cur.execute("UPDATE jobs SET state=?, endtime=?, task='', tasktime='0', alltasks=? WHERE rowid=?", [int(state), job_endtime, times, r['rowid']])
                sql_con.commit()

                # calculate the job time
                buildtime = job_endtime - r['starttime']
                print("build in # "+str(r['workernr'])+" finished with state "+str(state)+", buildtime: "+str(buildtime/60.0)+" mins")

                # check if the overall build is completed (if all jobs have completed)
                cur = sql_con.cursor()
                # fetch non-complete jobs
                cur.execute("SELECT rowid FROM jobs WHERE to_build =? AND endtime=0", [r['to_build']])
                row = cur.fetchone()
                if row:
                    print("not all jobs of build are done...")
                else:
                    print("Build done")
                    # check if all succeeded to store the correct state for the overall builds (either success or failed)
                    cur = sql_con.cursor()
                    cur.row_factory = sqlite3.Row
                    cur.execute("SELECT rowid, * FROM jobs WHERE to_build = ?", [r['to_build']])
                    build_end_state = BuildState.success
                    while True:
                        check_rows = cur.fetchmany(1000)
                        if not check_rows: break
                        for cr in check_rows:
                            if cr['state'] != BuildState.success:
                                # if one job did not succeed, flag overall build as failed
                                build_end_state = BuildState.failed
                                break

                    #TODO: check if time.time() is acceptable for a build endtime or if we should use the last jobs endtime
                    cur.execute("UPDATE builds SET state=?, endtime=? WHERE rowid=?", [int(build_end_state), time.time() , r['to_build']])
                    sql_con.commit()

            else:
                # build is still running
                buildtime = time.time() - r['starttime']
                #print("build in # "+str(r['workernr'])+" running since: "+str(buildtime/60.0)+" mins")
                #print("===================\n"+last_log_lines(logfile)+"===================\n\n")

# main runloop
while True:
    process_builds(sql_con)
    process_jobs(sql_con)

    # check if threads do need completion
    print("check threads")
    # itterate over a copy of build_threads in order to allow to manipulate the original list
    for build_start_thread in list(build_threads):
        if (build_start_thread.is_alive()):
            print("Thread with jobid "+str(build_start_thread.get_jobid())+" is still running...")
        else:
            print("Thread with jobid "+str(build_start_thread.get_jobid())+" is done...")
            if build_start_thread.get_returncode() == 0:
                print("Updating the database")
                cur = sql_con.cursor()
                cur.execute("UPDATE jobs SET state=? WHERE rowid=?", [BuildState.started, build_start_thread.get_jobid()])
                sql_con.commit()
            build_threads.remove(build_start_thread)

    # sleep for a while
    time.sleep(RUNLOOP_SLEEP)



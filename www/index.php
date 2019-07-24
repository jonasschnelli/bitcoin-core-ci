<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
$LOGDIR = "/home/jonasschnelli/discopop/logs/";

// if requested, show a ANSI formated log
if (isset($_REQUEST['ansilog']) && preg_match('/^[A-Za-z0-9\-\.]+$/', $_REQUEST['ansilog'])) {
  $logfile = $LOGDIR.$_REQUEST['ansilog'];
  system("cat $logfile | /home/jonasschnelli/discopop/aha/aha -s --black --title log-".$_REQUEST['ansilog']."");
  exit();
}

if (isset($_REQUEST['tarcontent'])) {
  $logfile = $LOGDIR.$_REQUEST['tarcontent'];
  echo $logfile;
  system("tar -tvf $logfile");
  exit();
}

class BuildsDB extends SQLite3 {
  function __construct() {
     $this->open('/home/jonasschnelli/discopop/data/builds.sqlite');
  }
}

$db = new BuildsDB();
$db->busyTimeout(5000);
if(!$db){
  echo $db->lastErrorMsg();
  exit();
}
$showbuilds = true; // show builds by default

// if build details are requested
$jobs_results = false;
if(isset($_REQUEST['build'])) {
  $statement = $db->prepare('SELECT rowid, * FROM jobs WHERE to_build=?');
  $statement->bindValue(1, $_REQUEST['build'], SQLITE3_INTEGER);
  $jobs_results = $statement->execute();
  if ($jobs_results != false) {
    $build_id = htmlentities(strip_tags($_REQUEST['build']));
  }
}

// if job details are requested
$job_row = false;
if(isset($_REQUEST['job'])) {
  $statement = $db->prepare('SELECT rowid, * FROM jobs WHERE uuid=?');
  $statement->bindValue(1, htmlentities(strip_tags($_REQUEST['job'])), SQLITE3_TEXT);
  $job_result = $statement->execute();
  if ($job_result != false) {
    $job_id = htmlentities(strip_tags($_REQUEST['job']));
    $job_row = $job_result->fetchArray();
    $statement = $db->prepare('SELECT rowid, * FROM builds WHERE rowid=?');
    $statement->bindValue(1, $job_row['to_build'], SQLITE3_INTEGER);
    $build_result = $statement->execute();
    if ($build_result != false) {
      $build_row = $build_result->fetchArray();
    }
  }
  $showbuilds = false;
}

if ($showbuilds) {
  $build_results = $db->query('SELECT rowid, * FROM builds  ORDER BY rowid DESC LIMIT 0, 10');
}

// manually add a build
if(isset($_REQUEST['addpull'])) {
  $pullnr = $_REQUEST['addpull'];
  $stmt = $db->prepare('INSERT INTO builds (image,repo,branch,pullnr) VALUES(?,?,?,?)');
  $stmt->bindValue(1, "ubuntu1804_base", SQLITE3_TEXT);
  $stmt->bindValue(2, "https://github.com/bitcoin/bitcoin", SQLITE3_TEXT);
  if (is_numeric($_REQUEST['addpull'])) {
   $stmt->bindValue(3, "refs/pull/".strval($pullnr)."/merge", SQLITE3_TEXT);
   $stmt->bindValue(4, strval($pullnr), SQLITE3_TEXT);
   $result = $stmt->execute();
   header("Location: index.php");
   exit(0);
  }
  else if ($_REQUEST['addpull']=="master") {
   $stmt->bindValue(3, "master", SQLITE3_TEXT);
   $result = $stmt->execute();
   header("Location: index.php");
   exit(0);
  }
}

function duration($start, $end) {
  if ($start == 0) {
    return "?";
  }
  if ($end == 0) {
    return gmdate("H:i:s", time()-$start);
  }
  return gmdate("H:i:s", $end-$start);
}

function job_is_running($row) {
  if ($row['endtime'] == 0) { return true; }
  return false;
}

function state_to_text($state) {
  if ($state == 0) return "queued";
  if ($state == 1) return "starting";
  if ($state == 2) return "running";
  if ($state == 3) return "failed";
  if ($state == 4) return "stalled";
  if ($state == 5) return "success";
  if ($state == 6) return "canceled";
  return "unknown";
}

function color_from_state($state) {
  if ($state == 0) return "queued";
  if ($state == 1) return "";
  if ($state == 2) return "is-warning";
  if ($state == 3) return "is-danger";
  if ($state == 4) return "is-danger";
  if ($state == 5) return "is-success";
  if ($state == 6) return "is-dark";
  return "unknown";
}

function success_from_state($state) {
  if ($state == 0) return "unknown";
  if ($state == 1) return "unknown";
  if ($state == 2) return "unknown";
  if ($state == 3) return "failed";
  if ($state == 4) return "failed";
  if ($state == 5) return "success";
  if ($state == 6) return "failed";
  return "unknown";
}

function is_final($state) {
  if ($state >= 3) return true;
  return false;
}

function human_filesize($bytes, $decimals = 2) {
  $sz = 'BKMGTP';
  $factor = floor((strlen($bytes) - 1) / 3);
  return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
}

function print_githead_from_log($logfile) {
  if (!file_exists($logfile)) { echo "unknown"; }
  system("head -1000 ".$logfile." | grep -oP '^###GITHEAD#\K([A-Za-z0-9]*)'");
}

$show_html_wrapper = !isset($_REQUEST['ajax']);
?><?php if($show_html_wrapper): ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.7.5/css/bulma.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
    <script defer src="https://use.fontawesome.com/releases/v5.3.1/js/all.js"></script>
  <title></title>

  
</head>
<body>
  
  <nav class="navbar is-dark" role="navigation" aria-label="main navigation">
    <div class="navbar-brand">
      <h1 class="title"></h1>
      <a role="button" class="navbar-burger burger" aria-label="menu" aria-expanded="false" data-target="navbarBasicExample">
        <span aria-hidden="true"></span>
        <span aria-hidden="true"></span>
        <span aria-hidden="true"></span>
      </a>
    </div>

    <div id="navbarBasicExample" class="navbar-menu">
      <div class="navbar-start">
        <a class="navbar-item" href="/">
          <img src="https://bitcoincore.org/assets/images/bitcoin_core_logo_colored_reversed.png" />&nbsp; | CI
        </a>
<!--
        <a class="navbar-item">
          Documentation
        </a>

        <div class="navbar-item has-dropdown is-hoverable">
          <a class="navbar-link">
            More
          </a>

          <div class="navbar-dropdown">
            <a class="navbar-item">
              About
            </a>
            <a class="navbar-item">
              Blabla
            </a>
            <a class="navbar-item">
              Contact
            </a>
            <hr class="navbar-divider">
            <a class="navbar-item">
              Report an issue
            </a>
          </div>
        </div> -->
      </div>

      <div class="navbar-end">
        <div class="navbar-item">
          <div class="buttons">
            <a class="button is-danger">
              <strong>Beta</strong>
            </a>
          </div>
        </div>
      </div>
    </div>
  </nav>

<?php endif; //htmlwrapper ?>
<?php if($showbuilds): ?>
    <?php if($show_html_wrapper): ?><section class="section" id="builds"><?php endif; ?>
      <div class="container">
        <h1>Last 10 builds</h1>
        <table class="table is-striped is-narrow is-hoverable is-fullwidth">
          <thead>
            <tr>
              <th scope="col">Build ID</th>
              <th scope="col">State</th>
              <th scope="col">Starttime</th>
              <th scope="col">Repo</th>
              <th scope="col">Branch</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $build_results->fetchArray()): ?>
            <tr class="<?php if(isset($_REQUEST['build']) && $_REQUEST['build'] == $row['rowid']) echo "is-selected"; ?>">
              <td><a href="?build=<?php echo $row['rowid']?>"><?php echo $row['rowid']?></a></td>
              <td><span class="tag <?php if($row['state'] == 3 || $row['state'] == 4) { echo "is-danger";} elseif($row['state'] == 5) { echo "is-success";} else { echo "is-warning"; } ?> is-small"><?php echo state_to_text($row['state']); ?></span></td>
              <td><?php echo date("Y-m-d H:i:s", $row['starttime']); ?></td>
              <td><?php echo $row['repo']?></td>
              <td><?php echo $row['branch']?></td>
            </tr>
            <?php endwhile;?>
          </tbody>
        </table>
        <?php if ($jobs_results != false): ?>
                <h1>Jobs for build <?php echo $build_id; ?></h1>
                <table class="table is-striped is-narrow is-hoverable is-fullwidth">
                  <thead>
                    <tr>
                      <th scope="col">Job #</th>
                      <th scope="col">State</th>
                      <th scope="col">Name</th>
                      <th scope="col">Duration</th>
                      <th scope="col">Current Task</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php $running = false; while ($row = $jobs_results->fetchArray()): 
                      if ($row['state'] == 1 || $row['state'] == 2) $running = true;
                      ?>
                    <tr>
                      <td><a href="?job=<?php echo $row['uuid']?>"><?php echo $row['rowid']?></a></td>
                      <td><?php if($row['state'] == 2 || $row['state'] == 1):?><a class="button is-loading is-small <?php if($row['state'] == 2) echo "is-warning"; ?>">Loading</a>&nbsp;<?php else: ?><span class="tag <?php if($row['state'] == 3 || $row['state'] == 4) { echo "is-danger";} elseif($row['state'] == 5) { echo "is-success";} else { echo "is-warning"; } ?> is-small"><?php echo state_to_text($row['state']); endif; ?></span></td>
                      <td><?php echo $row['name']?></td>
                      <td><?php echo duration($row['starttime'], $row['endtime']); ?></td>
                      <td><?php echo $row['task']; ?></td>
                    </tr>
                    <?php endwhile;?>
                  </tbody>
                </table>
        <?php endif; ?>
    </div>
  <?php if($show_html_wrapper): ?></section><?php endif; ?>
    <?php if($show_html_wrapper): ?>
      <script>
          $(document).ready(function(){
            (function poll() {
                $.ajax({
                    url: "/index.php?build=<?php echo $build_id; ?>&ajax=1",
                    type: "GET",
                    success: function(result) {
                        $("#builds").html(result);
                        console.log("polling");
                    },
                    complete: setTimeout(function() {poll()}, 5000),
                    timeout: 2000
                })
            })();
      		});
      	</script>
    <?php endif; // ajax update script?>
<?php endif; ?>

<?php if ($job_row != false):
    $row = $job_row;
    if ($row['state'] == 1 || $row['state'] == 2) $running = true;
?>
  <?php if($show_html_wrapper): ?><section class="section" id="jobdetail"><?php endif; ?>
      <div class="container">

<a href="index.php?build=<?php echo $row['to_build']; ?>">« part of build <?php echo $row['to_build']; ?></a>
&nbsp;<br /><br />
<section class="hero <?php
if($row['state'] == 2 || $row['state'] == 1) { echo "is-warning"; }
elseif($row['state'] == 3 || $row['state'] == 4) { echo "is-danger"; }
elseif($row['state'] == 5) { echo "is-success";}
?>" style="margin-bottom: 20px;">
  <div class="hero-body">
    <div class="container">
      <h1 class="title">
        Details for job <?php echo $row['uuid']; ?>
      </h1>
      <h2 class="subtitle">
        <?php echo state_to_text($row['state']); ?>
      </h2>
    </div>
  </div>
</section>


<div id="meta" class="field is-grouped is-grouped-multiline">
  <div class="control">
    <div class="tags has-addons">
      <span class="tag">Name</span>
      <span class="tag is-info"><?php echo $row['name']; ?></span>
    </div>
  </div>
  <div class="control">
    <div class="tags has-addons">
      <span class="tag">Success</span>
      <span class="tag <?php echo color_from_state($row['state']);?>"><?php echo success_from_state($row['state']);?></span>
      <input type="hidden" name="state" value="<?php echo $row['state']; ?>" id="state" />
    </div>
  </div>
  <div class="control">
    <div class="tags has-addons">
      <span class="tag">Build Time</span>
      <span class="tag is-dark"><?php echo duration($row['starttime'], $row['endtime']); ?></span>
    </div>
  </div>
  <div class="control">
    <div class="tags has-addons">
      <span class="tag">Git Head</span>
      <span class="tag is-dark"><?php print_githead_from_log($LOGDIR.$row['uuid'].".log"); ?></span>
    </div>
  </div>
  <?php if($row['task']): ?>
  <div class="control">
    <div class="tags has-addons">
      <span class="tag">Current Task</span>
      <span class="tag is-dark"><?php echo $row['task']; ?></span>
    </div>
  </div>
  <?php endif; ?>
</div>

<div id="meta" class="field is-grouped is-grouped-multiline">
  <div class="control">
    <div class="tags has-addons">
      <span class="tag">Repository</span>
      <span class="tag is-light"><a href="<?php echo $build_row['repo']; ?>" target="_blank"><?php echo $build_row['repo']; ?></a></span>
    </div>
  </div>
  <div class="control">
    <div class="tags has-addons">
      <span class="tag">Branch</span>
      <span class="tag is-light"><a href="<?php echo $build_row['repo']."/tree/".$build_row['branch']; ?>" target="_blank"><?php echo $build_row['branch']; ?></a></span>
    </div>
  </div>
  <?php if($build_row['pullnr']): ?>
  <div class="control">
    <div class="tags has-addons">
      <span class="tag">PR</span>
      <span class="tag is-primary"><a href="<?php echo $build_row['repo']; ?>/pull/<?php echo $build_row['pullnr']; ?>" target="_blank"><?php echo $build_row['pullnr']; ?></a></span>
    </div>
  </div>
  <?php endif; ?>
  <div class="control">
    <div class="tags has-addons">
      <span class="tag">Worker #</span>
      <span class="tag is-dark"><?php echo $row['workernr']; ?></span>
    </div>
  </div>
  <div class="control">
    <div class="tags has-addons">
      <span class="tag">Base Image</span>
      <span class="tag is-primary"><?php echo $row['baseimage']; ?></span>
    </div>
  </div>
</div>

<?php if($build_row['pullnr']): ?>
<article class="message is-primary">
  <div class="message-body">
    <h2 class="title is-4">Pull request info</h2>
    <div id="github-info">
      <div id="meta" class="field is-grouped is-grouped-multiline">
        <div class="control">
          <div class="tags has-addons">
            <span class="tag">Head</span>
            <span class="tag is-dark" id="github-head"></span>
          </div>
        </div>
        <div class="control">
          <div class="tags has-addons">
            <span class="tag">Title</span>
            <span class="tag is-dark" id="github-title"></span>
          </div>
        </div>
        <div class="control">
          <div class="tags has-addons">
            <span class="tag">Mergable</span>
            <span class="tag is-dark" id="github-mergeable"></span>
          </div>
        </div>
        <div class="control">
          <div class="tags has-addons">
            <span class="tag">Changes</span>
            <span class="tag is-dark" id="github-files"></span>
          </div>
        </div>
        <div class="control">
          <div class="tags has-addons">
            <span class="tag">Comments</span>
            <span class="tag is-dark" id="github-comments"></span>
          </div>
        </div>
        <div class="control">
          <div class="tags has-addons">
            <span class="tag">User</span>
            <span class="tag is-light" id=""><a href="" target="_blank" id="github-avatar-link"><span id="github-avatar"></span></a></span>
          </div>
        </div>
      </div>
    </div>
  </div>
</article>
<?php endif; ?>
    <?php
    $logfilesize = file_exists($LOGDIR.$row['uuid'].".log") ? filesize($LOGDIR.$row['uuid'].".log") : 0;
    $buildresults = $LOGDIR.$row['uuid']."-buildresult.tar.gz";
    $buildfiles_filesize = ($row['state'] == 5 && file_exists($buildresults)) ? filesize($buildresults) : 0;
    if ($buildfiles_filesize > 0):
    ?>
<article class="message is-success">
  <div class="message-body">
    <h2 class="title is-4">Build results</h2>
    <a href="logs/<?php echo $row['uuid']."-buildresult.tar.gz"; ?>"><?php echo $row['uuid']."-buildresult.tar.gz"; ?></a> (<?php echo human_filesize($buildfiles_filesize); ?> / <?php echo "SHA256SUM: "; system("sha256sum ".$buildresults." | awk '{ print $1 }'")?>)
  </div>
</article>
<?php endif; // buildfile present ?>
<article class="message is-dark">
  <div class="message-body">
    <h2 class="title is-4">Tasks time consumption</h2>
    <table class="table is-bordered">
    <?php 
    
    $lines = explode("\n", $row['alltasks']);

    foreach($lines as $line) {
      if (preg_match("/^###([^#]*)#([^#]*)#([0-9]*)/", $line, $matches)) {
        if ($matches[1] == "START") {
          $state = 1;
          $starttime = $matches[3];
        }
        if ($state == 1 && $matches[1] == "END") {
          $state = 0;
          echo "<tr><td>".duration($starttime, $matches[3])." - ".$matches[2]." </td></tr>";
        }
      }
    }
    ?></table>
  </div>
</article>
    <h2 class="title is-4">Build log tail</h2>
    <nav class="level is-mobile">
      <div class="level-item">
        <a href="logs/<?php echo $row['uuid']?>.log">Full-log plaintext (<?php echo human_filesize($logfilesize); ?>)</a>
      </div>
      <div class="level-item">
        <a href="index.php?ansilog=<?php echo $row['uuid']?>.log">Full-log ANSI (<?php echo human_filesize($logfilesize); ?>)</a>
      </div>
    </nav>
    <div class="box"><pre id="lastlog">
      <?php
      $nolines = isset($_REQUEST['lines']) ? $_REQUEST['lines'] : 100;
      $file = $LOGDIR.$row['uuid'].".log";
      system("tail -n ".preg_replace("/[^\d]/", "",$nolines)." ".$file." | aha --no-header");
      ?>
    </pre>
    </div>
<?php if($show_html_wrapper): ?></section><?php endif; ?>
<?php if($show_html_wrapper): ?>
  <script>
      $(document).ready(function(){
        (function poll() {
            if($("#state").val() < 3) {
              $.ajax({
                  url: "/index.php?job=<?php echo $row['uuid']; ?>&ajax=1",
                  type: "GET",
                  success: function(result) {
                      $("#jobdetail").html(result);
                      if (github_content_cache != "") {
                        $("#github-info").html(github_content_cache);
                      }
                      console.log("polling");
                  },
                  complete: setTimeout(function() {poll()}, 5000),
                  timeout: 2000
              })
            }
        })();
        
        <?php if($build_row['pullnr']): ?>
        var github_content_cache = ""
        $.ajax({
          url: "https://api.github.com/repos/bitcoin/bitcoin/pulls/<?php echo $build_row['pullnr']; ?>",
          context: document.body
        }).done(function(msg) {
          $("#github-head").html(msg.head.sha.substr(0,8));
          if (msg.mergeable) {
            $("#github-mergeable").html(msg.mergeable);
          }
          else {
            $("#github-mergeable").html("no");
          }
          $("#github-title").html(msg.title);
          $("#github-files").html(msg.changed_files+" (+"+msg.additions+"/-"+msg.deletions+")");
          $("#github-comments").html(msg.review_comments);
          $("#github-avatar").html("<img src=\""+msg.user.avatar_url+"\" width=\"40\" height=\"40\">");
          $("#github-avatar-link").attr("href", msg.user.html_url);
          github_content_cache = $("#github-info").html();
        });
        <?php endif; ?>
  		});
  	</script>
<?php endif; // ajax update script?>
<?php endif; // job detail ?>  
<?php if($show_html_wrapper): ?>
  <?php if($showbuilds): ?>
  <section class="section">
      <div class="container">
        <h1>Add PR</h1>
        <form action="" method="get" accept-charset="utf-8">
        
          <input type="text" name="addpull" value="" id="addbuild">
          <p><input type="submit" value="Continue &rarr;"></p>
        </form>
      </div>
    </section>
  <?php endif; ?>
    <footer class="footer">
      <div class="content has-text-centered">
        <p>
          <strong>Bitcoin Core CI</strong> by <a href="https://github.com/jonasschnelli">Jonas Schnelli</a>. The source code is licensed
          <a href="http://opensource.org/licenses/mit-license.php">MIT</a>. The website content
          is licensed <a href="http://creativecommons.org/licenses/by-nc-sa/4.0/">CC BY NC SA 4.0</a>.
        </p>
      </div>
    </footer>
</body>
</html>
<?php endif; ?>
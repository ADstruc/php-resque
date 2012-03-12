<?php
$QUEUE = getenv('QUEUE');
if(empty($QUEUE)) {
	die("Set QUEUE env var containing the list of queues to work.\n");
}

require_once 'lib/Resque.php';
require_once 'lib/Resque/Worker.php';

$logLevel = 0;
$LOGGING = getenv('LOGGING');
$VERBOSE = getenv('VERBOSE');
$VVERBOSE = getenv('VVERBOSE');
if(!empty($LOGGING) || !empty($VERBOSE)) {
	$logLevel = Resque_Worker::LOG_NORMAL;
}
else if(!empty($VVERBOSE)) {
	$logLevel = Resque_Worker::LOG_VERBOSE;
}

$APP_INCLUDE = getenv('APP_INCLUDE');
if($APP_INCLUDE) {
	if(!file_exists($APP_INCLUDE)) {
		die('APP_INCLUDE ('.$APP_INCLUDE.") does not exist.\n");
	}

	require_once $APP_INCLUDE;
}

$REDIS_BACKEND = getenv('REDIS_BACKEND');
$REDIS_DATABASE = getenv('REDIS_DATABASE') ?: 0;
if(!empty($REDIS_BACKEND)) {
	Resque::setBackend($REDIS_BACKEND, $REDIS_DATABASE);
}

$interval = 5;
$INTERVAL = getenv('INTERVAL');
if(!empty($INTERVAL)) {
	$interval = $INTERVAL;
}

$queues = explode(',', $QUEUE);
$worker = new Resque_Worker($queues);
$worker->logLevel = $logLevel;

$PIDFILE = getenv('PIDFILE');
if ($PIDFILE) {
    file_put_contents($PIDFILE, getmypid()) or
        die('Could not write PID information to ' . $PIDFILE);
}

$msg = sprintf('*** Starting worker %s listening to %s/%d', $worker, $REDIS_BACKEND, $REDIS_DATABASE);

fwrite(STDOUT, $msg . "\n");
$worker->work($interval);

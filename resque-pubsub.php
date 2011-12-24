<?php
$QUEUE = getenv('QUEUE');
if(empty($QUEUE)) {
	die("Set QUEUE env var containing the list of queues to work.\n");
}

$APP_INCLUDE = getenv('APP_INCLUDE');
if($APP_INCLUDE) {
	if(!file_exists($APP_INCLUDE)) {
		die('APP_INCLUDE ('.$APP_INCLUDE.") does not exist.\n");
	}

	require_once $APP_INCLUDE;
}

require_once 'lib/Resque.php';
require_once 'lib/Resque/Worker.php';

$REDIS_BACKEND = getenv('REDIS_BACKEND');
$REDIS_DATABASE = getenv('REDIS_DATABASE') ?: 0;
if(!empty($REDIS_BACKEND)) {
	Resque::setBackend($REDIS_BACKEND, $REDIS_DATABASE);
}

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


$queues = explode(',', $QUEUE);
$worker = new Resque_Worker($queues);
$worker->logLevel = $logLevel;

$PIDFILE = getenv('PIDFILE');
if ($PIDFILE) {
    file_put_contents($PIDFILE, getmypid()) or
        die('Could not write PID information to ' . $PIDFILE);
}

fwrite(STDOUT, '*** Starting worker '.$worker."\n");
$worker->subscribe('email');
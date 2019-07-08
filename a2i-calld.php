#!/usr/bin/php -q
<?php

//declare(ticks = 1);

use CCS\Logger;
use CCS\WebsocketApiClient;
use CCS\db\MyDB;
use CCS\a2i\Calld;
use CCS\a2i\DBLogger;

$realPath  = realpath(__FILE__);
$pathParts = pathinfo($realPath);
$myDir =  $pathParts['dirname'];
$myName = $pathParts['filename'];

// dynamic class load
//@phan-suppress-next-line PhanTypeMismatchArgumentInternal
spl_autoload_register(function ($class) {
    global $myDir;

    //echo "$class\n";
    // namespace -> file structure
    $relPath = str_replace(__NAMESPACE__, "", $class);
    $relPath .= ".class.php";
    $filename = "$myDir/lib/$relPath";
    $filename = str_replace("\\", "/", $filename);

    if (file_exists($filename)) {
        //echo "exists $filename\n";
        require_once $filename;
        return true;
    }
    //echo "not exists $filename\n";
    return false;
});

require_once('Mail.php');
require_once $myDir . '/vendor/autoload.php';
require_once $myDir . '/_config.php';

Logger::setLogTime(true);

if (!isset($argv[1]) || $argv[1] == '') {
    Logger::log("Invalid arguments.");
    Logger::log("Usage:");
    Logger::log("$argv[0] CAMPAIGN\n");
    exit(1);
}

$CAMPAIGN = $argv[1];

if (!isset($_CFG['db']['conn-params']) || !isset($_CFG['db']['servers'])) {
    Logger::log("DB parameters not set in config, exit");
    exit(0);
}

// Connect DB
$db = MyDB::getInstance();
$db->init($_CFG['db']['servers'], $_CFG['db']['conn-params'], $quiet = false);
$result = $db->connect();
if ($result->error()) {
    Logger::log("DB connection error: " . $result->errorDesc());
    exit(0);
}

if (!$db->tableExist("$CAMPAIGN")) {
    Logger::log("Campaign table $CAMPAIGN doesnt exist or is not accessible, exit");
    exit(3);
}

$mandOptions = ['CALL_LOG_TABLE','TRUNK_LOCATION_TABLE','AMI_SERVERS','EMAIL_FROM','EMAIL_HOST','EMAIL_PORT',
    'X_SEND_TRIES_MAX', 'X_SEND_TRIES_INTVL', 'db', 'api'];

foreach ($mandOptions as $option) {
    if (!isset($_CFG[$option])) {
        Logger::log($_CFG[$option] . "  not set in config, exit");
        exit(0);
    }
}

fclose(STDIN);
fclose(STDOUT);
fclose(STDERR);

$STDIN  = fopen('/dev/null', 'r');
$STDOUT = fopen(dirname(__FILE__)."/log/${CAMPAIGN}.log", 'ab');
$STDERR = fopen(dirname(__FILE__)."/log/${CAMPAIGN}_error.log", 'ab');

// Detach from console
$child_pid = pcntl_fork();
if ($child_pid) {
    // Exit parent process
    exit(0);
}
// Make child process main
posix_setsid();

// Reconnect to DB. After fork connection may lost
$db->disconnect();
$result = $db->connect();
if ($result->error()) {
    Logger::log("DB connection error: " . $result->errorDesc());
    exit(0);
}

if (!isset($_CFG['api']['url']) || !$_CFG['api']['url']) {
    Logger::log("_CFG['api']['url'] not set or empty");
    exit(0);
}

if (!isset($_CFG['api']['authtoken']) || !$_CFG['api']['authtoken']) {
    Logger::log("_CFG['api']['authtoken'] not set or empty");
    exit(0);
}

$dblogger = new DBLogger($db, $_CFG['CALL_LOG_TABLE']);
$trunkLocTable = $_CFG['TRUNK_LOCATION_TABLE'];
$amiServers = $_CFG['AMI_SERVERS'];

$apiToken = $_CFG['api']['authtoken'];

$apiAddress = $_CFG['api']['address'];
$apiPort  = $_CFG['api']['port'];
$apiUseSSL  = $_CFG['api']['ssl'];
$apiConnTimeout  = $_CFG['api']['connect-timeout'];
$apiReconnectInterval  = $_CFG['api']['reconnect-interval'];

$apiClient = new WebsocketApiClient($apiAddress, $apiPort, $apiToken, $apiUseSSL, $apiConnTimeout);
$apiClient->onClose(function () use ($apiClient, $apiReconnectInterval) {
    Logger::log("API connection closed");
    while (!$apiClient->isConnected()) {
        Logger::log("Trying to reconnect API...");
        $apiClient->connect();
        //@phan-suppress-next-line PhanUndeclaredClassMethod
        co::sleep($apiReconnectInterval);
    }
    Logger::log("API reconnect success!");
});


$callDaemon = new Calld($CAMPAIGN, $db, $dblogger, $_CFG, $apiClient);

$sigHandler = function ($signo) use ($callDaemon) {
    switch ($signo) {
        //@phan-suppress-next-line PhanUndeclaredConstant
        case SIGQUIT: // Correct shutdown
//            Logger::log("SIGQUIT received");
            $callDaemon->stop("SIGQUIT");
            break;
        //@phan-suppress-next-line PhanUndeclaredConstant
        case SIGINT:
//            Logger::log("SIGINT received");
            $callDaemon->stop("SIGINT");
            break;
        default:
            Logger::log("Unknown signal received: " . $signo);
            break;
    }
    //swoole_event_exit();
};

// For unknown reason, we can set signal handler for all signals(except KILL/STOP)
// but can't for: SIGALRM,SIGHUP,SIGPIPE,SIGVTALRM
// We will use SIGQUIT as command to gracefully stop campaign. Watchdog will not
// try to restart campaign after such stop.
// We will use SIGINT as system shutdown command (when restarts service, which started
// current process, current process will receive SIGINT too)
// watchdog will restart process stopped with SIGINT

//@phan-suppress-next-line PhanUndeclaredConstant
pcntl_signal(SIGINT, $sigHandler); // "System" shutdown signal, when system reboots or whatever
//@phan-suppress-next-line PhanUndeclaredConstant
pcntl_signal(SIGQUIT, $sigHandler); // "User" shutdown signal, used to gracefully stop campaign
// Cannot set handler for SIGTERM
//pcntl_signal(SIGTERM, 'childSignalHandler');

$init = function() use($apiClient, $callDaemon) {
    $ret = $apiClient->connect();
    if($ret->error()) {
        Logger::log("API connection failed: " . $ret->errorDesc());
        exit(0);
    }
    Logger::log("API connection success");

    $ret = $apiClient->eventsSubscribe(['ALL']);
    if($ret->error()) {
        Logger::log("API events subscription failed: " . $ret->errorDesc());
    } else {
        Logger::log("API events subscription OK");
    }

    $callDaemon->run();
};

swoole_timer_after(1,$init);

//exit(0);

/*
function childSignalHandler($signo) {
    global $callDaemon;
    switch($signo) {
//        case SIGTERM: // "System" shutdown signal
//            Logger::log("SIGTERM received");
//            $callDaemon->stop("SIGTERM");
//            break;
        case SIGINT: // "User" shutdown signal
            Logger::log("SIGINT received");
            $callDaemon->stop("SIGINT");
            break;
        default:
            Logger::log("Unknown signal received: " . $signo);
            break;
    }
}
*/
?>

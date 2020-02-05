#!/usr/bin/php -q
<?php
// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

declare(ticks = 1);

use CCS\Logger;
use CCS\db\MyDB;
use CCS\util\_;

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
require_once $myDir . '/_config.php';

$childSignalHandler = function ($signo, $pid = null, $status = null) {
    global $stop_server;
    switch ($signo) {
        case SIGTERM:
        case SIGINT:
            $stop_server = true;
            break;
        default:
            break;
    }
};

pcntl_signal(SIGTERM, $childSignalHandler);
pcntl_signal(SIGINT, $childSignalHandler);

$appTypes = [
    'sms'  => './a2i-smsd.php',
    'call' => './a2i-calld.php',
];

$notifyEmail = function ($msg) {
    global $_CFG;

    if (
        !isset($_CFG['EMAIL_ADMIN'])
        || !isset($_CFG['EMAIL_FROM'])
        || !isset($_CFG['EMAIL_HOST'])
        || !isset($_CFG['EMAIL_PORT'])
    ) {
        return;
    }

    $email = $_CFG['EMAIL_ADMIN'];
    $mailFrom = $_CFG['EMAIL_FROM'];
    $mailHost  = $_CFG['EMAIL_HOST'];
    $mailPort  = $_CFG['EMAIL_PORT'];

    $fEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
    if ($fEmail === false) {
        return false;
    } // empty/incorrect address
    _::email($mailFrom, $fEmail, $msg, $msg, $mailHost, $mailPort);
};

// Connect DB
$db = MyDB::getInstance();
$db->init($_CFG['db']['servers'], $_CFG['db']['conn-params'], $quiet = false);
$result = $db->connect();
if ($result->error()) {
    Logger::log("DB connection error: " . $result->errorDesc());
    exit(0);
}

$stop_server = false;
$hostname = gethostname();

Logger::log("daemon started");

$warnNoSuchCampShown = []; // campaigns where warnings were shown

while (!$stop_server) {
    $rows = $db->query("select s_campaign from a2i_campaigns");
    foreach ($rows as $row) { // for every campaign
        $CAMPAIGN = $row['s_campaign'];
        if (!$db->tableExist("$CAMPAIGN")) {
            if (!isset($warnNoSuchCampShown[$CAMPAIGN])) { // warning not shown
                $msg = "Campaign table $CAMPAIGN doesnt exist or is not accessible";
                Logger::log($msg);
                $notifyEmail($msg);

                $warnNoSuchCampShown[$CAMPAIGN] = true;
            }
            continue;
        }

        $aCampStatus = $db->deserializeEntity($CAMPAIGN, 'status', 'status');

        if (!count($aCampStatus) || !isset($aCampStatus['status'])) {
            //Logger::log("$CAMPAIGN : couldnt deserialize, or campaign just created(1)");
            continue;
        }

        $aCampStatus = $aCampStatus['status'];

        if (!isset($aCampStatus['status']) || !isset($aCampStatus['hostname']) ||
         !isset($aCampStatus['pid']) || !isset($aCampStatus['type'])) {
            //Logger::log("$CAMPAIGN : couldnt deserialize, or campaign just created(2)");
            continue;
        }

        if ($aCampStatus['hostname'] != $hostname) {
            //Logger::log("$CAMPAIGN : hostname -> ".$aCampStatus['hostname']);
            continue; // runs not on this host
        }

        $sCampStatus = $aCampStatus['status'];
        $sCampReason = isset($aCampStatus['reason'])?$aCampStatus['reason']:'';
        $campPid = isset($aCampStatus['pid'])?$aCampStatus['pid']:'';

        if ($sCampStatus != 'running' /* && $sCampReason != "SIGINT" */) {
            //Logger::log("$CAMPAIGN : status -> ".$aCampStatus['status']);
            continue; // not interesting
        }

        // Campaign status is 'running'
        //check all's OK

        if (file_exists("/proc/$campPid")) {
            //Logger::log("$CAMPAIGN : process with a pid = $campPid is running");
            continue;
        }

        // Something bad with campaign, mark as aborted
        $msg = "Campaign aborted: $CAMPAIGN (host:$hostname pid:" . $aCampStatus['pid'] . " status:$sCampStatus reason:$sCampReason)";
        Logger::log($msg);
        $notifyEmail($msg);

        $aCampStatus['status'] = 'aborted';
        $aCampStatus['pid'] = '';
        $db->serializeEntity($CAMPAIGN, 'status', ['status' => $aCampStatus]);

/*
        $campType = $aCampStatus['type']; // sms/a2i
        Logger::log("$CAMPAIGN : type -> $campType");

        if (isset($appTypes[$campType]) && $appTypes[$campType] != '' && file_exists($appTypes[$campType])) {
            $cmd = $appTypes[$campType]." ".$CAMPAIGN;
            Logger::log("restarting: $cmd");
            exec($cmd);
        }
*/
    }
    sleep(1);
}

Logger::log("daemon stopped");

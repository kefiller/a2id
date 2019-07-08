#!/usr/bin/php -q
<?php

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
require_once $myDir . '/lib/local/_funcs.php';

if (!isset($argv[1]) || $argv[1] == '') {
    out("Invalid arguments.");
    out("Usage:");
    out("$argv[0] CAMPAIGN\n");
    exit(1);
}

$CAMPAIGN = $argv[1];

$db = new MyDB($_CFG['DB_SERVERS'], $_CFG['DB_CONN_PARAMS']);
if (!$db->connect()) {
    die('Could not connect db');
}

if (!$db->tableExist("$CAMPAIGN")) {
    out("Campaign table $CAMPAIGN doesnt exist or is not accessible, exit");
    exit(3);
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

$childSignalHandler = function ($signo, $pid = null, $status = null) {
    global $smsDaemon;
    switch ($signo) {
        case SIGTERM:
            $smsDaemon->stop("SIGTERM");
            break;
        case SIGQUIT:
            $smsDaemon->stop("SIGQUIT");
            break;
        default:
            break;
    }
};

pcntl_signal(SIGTERM, $childSignalHandler);
pcntl_signal(SIGQUIT, $childSignalHandler);

$smsDaemon = new SMSd($CAMPAIGN, $db);
$smsDaemon->run();

exit(0);

?>

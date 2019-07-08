#!/usr/bin/php -q
<?php

use CCS\TTS;
use CCS\db\MyDB;
use CCS\a2i\Call;

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

if ($argc < 4) {
    echo "error^invalid arguments";
    exit(1);
}

// actions:
// get-tts - получить TTS-запись (путь к файлу)
$actions = ['get-tts'];

$action   = $argv[1];
$CAMPAIGN = $argv[2];
$number   = $argv[3];

if (!in_array($action, $actions)) {
    echo "error^invalid action";
    exit(1);
}

$db = new MyDB($_CFG['DB_SERVERS'], $_CFG['DB_CONN_PARAMS'], $quiet = true);
if (!$db->connect()) {
    echo "error^could not connect db";
    exit(1);
}

if (!$db->tableExist("$CAMPAIGN")) {
    echo "error^campaign table $CAMPAIGN doesnt exist or is not accessible";
    exit(1);
}

// Читаем настройки из таблицы кампании:
$campSettings = $db->deserializeEntity($CAMPAIGN, 'settings');

$call = new Call($db, $CAMPAIGN, $number, $campSettings['settings'], null);
if (!$call->deserialize()) {
    echo "error^could not deserialize data for $number";
    exit(1);
}

// Если для звонка установлено имя записи, возвращаем ее.
if ($call->getRecord() != '') {
    echo "ok^".$call->getRecord();
    exit(0);
}

// Если пустое сообщение
if ($call->getMessage() == '') {
    echo "error^nor message or record is set for $number";
    exit(1);
}

$msg = $call->getMessage();
$tts = new TTS($msg);

// try to get generated message
$ret = $tts->get();
if ($ret->error()) {
    echo "error^" . $ret->errorDesc();
    exit(1);
}

echo "ok^" . $ret->data();

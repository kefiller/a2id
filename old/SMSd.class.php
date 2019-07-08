<?php

namespace local;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, PSR2.Classes.PropertyDeclaration.Underscore
class SMSd
{
    private $_campaign;
    private $_campSettType = 'settings';

    private $_db;
    private $_dbLogger;
    private $_smsSender;

    private $_stopServer = false;
    private $_stopReason;

    private $_dtFormat = 'Y-m-d H:i:s';

    private $_status = [];

    public function __construct($campaign, &$db)
    {
        global $_CFG;

        $this->_campaign = $campaign;
        $this->_db = $db;

        $this->_dbLogger  = new DBLogger($this->_db, $this->_campaign, $_CFG['SMS_LOG_TABLE']);
        $this->_smsSender = new SMSSenderPik();

        $this->_status['status']   = 'running';
        $this->_status['hostname'] = gethostname();
        $this->_status['pid']      = getmypid();
        $this->_status['type']     = 'sms';
    }

    public function run()
    {
        global $_CFG;
        out("Daemon started");

        $this->_dbLogger->log('', ['type' => 'start']);
        $this->updateStatus();

        $campSettings = false; // Настройки кампании
        $bNotWorkTimeMsgShown = false;

        while (!$this->_stopServer) {
            // Читаем из таблицы кампании:
            $aCampSettings = $this->_db->deserializeEntity($this->_campaign, $this->_campSettType);

            $mandFields = ['callerid','interval-wtime','interval-dow','amount','retry',
            'retry-secs','interval-send','msg-template'];
            foreach ($aCampSettings as $settName => $setts) {
                // Проверим наличие обязательных полей
                if (!hasMandatoryKeys($mandFields, $setts)) {
                    continue;
                }
                $campSettings = $setts;
                break;
            }

            // Если нет, или некорректные, или нет активных завершаем работу кампании
            if (!$campSettings) {
                $this->stop("No valid settings for campaign, check mandatory fields: ".implode(',', $mandFields));
                continue;
            }

            // Номера, работа с которыми еще не завершена
            $sql = "select s_name from ".$this->_campaign.
            " where s_type = 'number' and case when (s_def::json->>'x-finished')
             is not NULL then s_def::json->>'x-finished' <> 'true' else true end";
            $rows = $this->_db->query($sql);

            // Если таких номеров нет, завершаем кампанию (или можно выполнить действие по настройкам кампании)
            if (count($rows) == 0) {
                $this->stop("No numbers left for processing");
                continue;
            }

            $checker = new MsgChecker($campSettings);

            // Проверить, не истек ли срок действия кампании
            if ($checker->checkCampaignExpiry()) {
                $$this->stop("Campaign has expired");
                continue;
            }

            // По каждому номеру:
            foreach ($rows as $row) {
                if (!isset($row['s_name']) || $row['s_name'] == '') {
                    continue;
                }
                $sNumber = $row['s_name'];

                $msg = new SMSMessage($this->_db, $this->_campaign, $sNumber, $campSettings, $this->_smsSender);
                if (!$msg->deserialize()) {
                    out("Could not deserialize data for $sNumber");
                    continue; // не получилось десериализовать
                }
                // Сохраним для лога
                $origMsg = $msg->getAggrData();

                $checker->set($msg->getAggrData());

                // Проверим статус отправки сообщения. Если он пуст, сообщение ни разу не отправлялось.
                // Если он == 'queued',
                // сообщение находится в очереди доставки и надо уточнить его статус. Иначе там success|error
                $msgSendStatus = $msg->getSendStatus();

                // still queued
                if ($msgSendStatus == 'queued') {
                    $msgNewSendStatus = $msg->checkSendStatus();

                    if (!$msgNewSendStatus) {
                        $msg->serialize();
                        out("Could not request message status: '".$msg->getLastError());
                        $this->updateStatus($sNumber);
                        continue; // не смогли проверить статус сообщения
                    }

                    // still queued
                    if ($msgNewSendStatus == 'queued') {
                        $msg->serialize();
                        //out("$sNumber -> '".$msg->getMessage()."' : still queued, try ".$msg->getStatusCheckTries().",
                        // will retry later");
                        $this->updateStatus($sNumber);
                        continue; // все еще в очереди на доставку, отложим номер на потом
                    }
                    $msg->serialize();
                    $this->_dbLogger->log($sNumber, ['type' => 'change', 'diff' => $msg->getDiff($origMsg)]);
                    out("$sNumber -> '".$msg->getMessage()."' : $msgSendStatus -> ".$msgNewSendStatus."");
                    $this->updateStatus($sNumber);
                    continue;
                }

                // Проверить, успешно ли мы доставили все необходимые сообщения
                if ($checker->checkTriesSuccess()) { // пометим номер как завершенный
                    $msg->finish();
                    $msg->serialize();
                    $this->_dbLogger->log($sNumber, ['type' => 'change', 'diff' => $msg->getDiff($origMsg)]);
                    out("$sNumber -> '".$msg->getMessage()."' : marked as finished(success)");
                    $this->updateStatus($sNumber);
                    continue;
                }

                // Не превысили ли мы лимиты на отправку ообщений(общее кол-во попыток)
                if ($checker->checkTriesTotal()) {
                    // пометим номер как завершенный
                    $msg->finish();
                    $msg->serialize();
                    $this->_dbLogger->log($sNumber, ['type' => 'change', 'diff' => $msg->getDiff($origMsg)]);
                    out("$sNumber -> '".$msg->getMessage()."' : marked as finished(all tries left)");
                    $this->updateStatus($sNumber);
                    continue;
                }

                // Что-то еще надо делать...

                // Проверить возможность совершения действий (интервал рабочего времени кампании)
                if (!$checker->checkWorkTime()) {
                    if (!$bNotWorkTimeMsgShown) {
                        out("$sNumber -> '".$msg->getMessage()."' : not work time");
                        $bNotWorkTimeMsgShown = true;
                    }
                    $this->updateStatus($sNumber);
                    continue;
                } else {
                    $bNotWorkTimeMsgShown = false;
                }

                // Проверить интервал отправки сообщений (в случае последней успешной и неуспешной доставки)
                if (!$checker->checkSendInterval()) {
                    //out("$sNumber -> '".$msg->getMessage()."' : time interval not reached");
                    $this->updateStatus($sNumber);
                    continue;
                }

                // Пытаемся отправить сообщение
                //out("$sNumber -> '".$msg->getMessage()."' : sending message to delivery service");
                if (!$msg->send()) {
                    if ($msg->getLastError() != 'send interval not finished') {
                        out("$sNumber ->  '".$msg->getMessage()."' : couldn't send to delivery service, try "
                        .$msg->getSendTries().", reason: '".$msg->getLastError()."',  will retry later");
                    }
                } else {
                    if ($msg->getSendStatus() != '') {
                        out("$sNumber(sent to delivery service) -> '".$msg->getMessage()."' : msgSendStatus -> "
                        .$msg->getSendStatus());
                    }
                    $this->_dbLogger->log($sNumber, ['type' => 'change', 'diff' => $msg->getDiff($origMsg)]);
                }

                $msg->serialize();
                $this->updateStatus($sNumber);
            }
            usleep(500000);
        }
        if ($this->_stopReason != "SIGTERM") {
            $this->notifyEmail($campSettings);
        }
        $this->updateStatus($sNumber);
        $this->_dbLogger->log('', ['type' => 'stop', 'reason' => $this->_stopReason]);
        out("Daemon stopped: ".$this->_stopReason);
    }

    public function stop($sReason)
    {
        $this->_stopServer = true;
        $this->_stopReason = $sReason;
        if ($sReason != "SIGTERM") { // корректное завершение кампании по сигналу
            // если получен SIGTERM, процесс будет перезапущен smsd_watchdog'ом
            $this->_status['status'] = 'stopped';
            $this->_status['pid'] = '';
        }
    }

    private function notifyEmail($campSettings)
    {
        global $_CFG;
        if (!isset($campSettings['emails']) || $campSettings['emails'] == '') {
            return false;
        }

        $emails = explode(',', $campSettings['emails']);
        foreach ($emails as $email) {
            $fEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
            if ($fEmail === false) {
                continue;
            } // empty/incorrect address
            $msg = $this->_campaign." finished: ".$this->_stopReason;
            email($_CFG['EMAIL_FROM'], $fEmail, $msg, $msg);
        }
    }

    private function updateStatus($lastNumber = '')
    {
        $this->_status['lastStatusUpdate'] = date($this->_dtFormat);
        if ($lastNumber != '') {
            $this->_status['lastNumber'] = $lastNumber;
        }
        $this->_db->serializeEntity($this->_campaign, 'status', ['status' => $this->_status]);
    }
}

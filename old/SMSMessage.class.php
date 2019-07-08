<?php

namespace local;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, PSR2.Classes.PropertyDeclaration.Underscore
class SMSMessage
{
    private $_db = false;
    private $_table = false;
    private $_s_type = 'number';
    private $_number = false;
    private $_campSettings = false;
    private $_smsSender = false;

    private $_data = false;
    private $_aggrData = false;

    private $_dtFormat = 'Y-m-d H:i:s';
    private $_zTimeStamp = '1970-01-01 03:00:00'; // zero timestamp - unixtime = 0

    private $_lastError = '';

    public function __construct($db, $table, $sNumber, $campSettings, $smsSender)
    {
        $this->_db = $db;
        $this->_table = $table;
        $this->_number = $sNumber;
        $this->_campSettings = $campSettings;
        $this->_smsSender = $smsSender;
    }

    public function deserialize()
    {
        if (!$this->_db || !$this->_table || !$this->_number || !$this->_campSettings) {
            return false;
        }

        $this->_data = $this->_db->deserializeEntity($this->_table, $this->_s_type, $this->_number);
        if (count($this->_data) == 0 || !isset($this->_data[$this->_number])) {
            return false;
        }

        $this->_data = $this->_data[$this->_number];

        // для номера не определены доп. атрибуты. Значит, номер новый, надо добавить
        if (!isset($this->_data['x-tries-total'])) {
            $this->_data['x-tries-total'] = 0;                  // общее кол-во попыток отправки
            $this->_data['x-tries-success'] = 0;                // кол-во успешных попыток
            $this->_data['x-tries-error'] = 0;                  // кол-во неудачных попыток
            $this->_data['x-send-date'] = $this->_zTimeStamp;   // дата последней попытки отправки
            $this->_data['x-send-status'] = "";                 // статус последней отправки
            $this->_data['x-msg-id'] = "";                      // ID сообщения из последней отправки
            // кол-во попыток проверки статуса. Если больше X_SEND_STATUS_CHECK_TRIES_MAX
            // статус последней отправки выставляется в ошибочный.
            $this->_data['x-send-status-check-tries'] = 0;
            $this->_data['x-send-status-check-date'] = $this->_zTimeStamp; // дата последней проверки статуса отправки
            // кол-во попыток отправки сообщения(в систему доставки). Если больше
            // X_SEND_TRIES_MAX, увеличивается кол-во (+неудачных) попыток отправки,
            // статус последней отправки выставляется в ошибочный.
            $this->_data['x-send-tries'] = 0;
            $this->_data['x-send-try-date'] = $this->_zTimeStamp; // дата последней попытки отправки сообщения
            $this->_data['x-finished'] = 'false';               // флаг завершения обработки номера
        }

        $this->buildAggregatedSettings();

        // Подставим переменные в шаблон сообщения
        if (isset($this->_aggrData['msg-template'])) {
            $this->_data['message'] = substTemplate($this->_aggrData['msg-template'], $this->_aggrData);
            $this->_aggrData['message'] = $this->_data['message'];
        }

        return true;
    }

    public function serialize()
    {
        $this->_db->serializeEntity($this->_table, $this->_s_type, [$this->_number => $this->_data]);
    }

    public function getAggrData()
    {
        $this->buildAggregatedSettings();
        return $this->_aggrData;
    }

    public function getMessage()
    {
        return isset($this->_aggrData['message'])?$this->_aggrData['message']:'';
    }

    public function getSendStatus()
    {
        return $this->_data['x-send-status'];
    }

    public function getStatusCheckTries()
    {
        return $this->_data['x-send-status-check-tries'];
    }

    public function getSendTries()
    {
        return $this->_data['x-send-tries'];
    }

    public function checkSendStatus()
    {
        global $_CFG;
        // мы не превысили кол-во повторных запросов статуса сообщения
        if ($this->_data['x-send-status-check-tries'] < $_CFG['X_SEND_STATUS_CHECK_TRIES_MAX']) {
            // сколько прошло времени с момента последней проверки статуса

            // Текущие дата и время
            $dtNow = date($this->_dtFormat);

            // Unixtime
            $tmNow = strtotime($dtNow);

            // Время последней отправки
            $tmLastCheck = strtotime($this->_data['x-send-status-check-date']);

            // Интервал между последней отправкой
            $tmDiff = $tmNow - $tmLastCheck;

            if ($tmDiff < $_CFG['X_SEND_STATUS_CHECK_INTVL']) {
                // прошло меньше времени, чем заданный интервал проверки статуса
                $this->setLastError("check interval not finished");
                return $this->_data['x-send-status'];
            }

            // запросим статус доставки сообщения
            $msgStatus = $this->_smsSender->getMessageStatus($this->_data['x-msg-id']);

            //обновим время последней отправки
            $this->_data['x-send-status-check-date'] = $dtNow;

            if (!$msgStatus) { // ошибка запроса статуса
                $this->setLastError($this->_smsSender->getLastError()); // уточним ошибку
                $this->_data['x-send-status-check-tries']++; // и обновим счетчик запросов
                return false;
            }

            $this->_data['x-send-status'] = $msgStatus;
            $this->_data['x-send-status-check-tries']++; // и обновим счетчик запросов

            if ($this->_data['x-send-status'] == 'success') {     // сообщение было успешно доставлено
                $this->_data['x-tries-success']++;
                $this->_data['x-send-status-check-tries'] = 0; // обнулим счетчит запроса статуса
                // обнулим время последнего запроса статуса
                $this->_data['x-send-status-check-date'] = $this->_zTimeStamp;
            } elseif ($this->_data['x-send-status'] == 'error') { // сообщение не было успешно доставлено
                $this->_data['x-tries-error']++;
                $this->_data['x-send-status-check-tries'] = 0; // обнулим счетчит запроса статуса
                // обнулим время последнего запроса статуса
                $this->_data['x-send-status-check-date'] = $this->_zTimeStamp;
            } // else queued
            return $this->_data['x-send-status'];
        }
        // cannot leave queued status, mark as error
        $this->_data['x-send-status'] = 'error';
        $this->_data['x-tries-error']++;
        $this->_data['x-send-status-check-tries'] = 0; // обнулим счетчит запроса статуса
        $this->_data['x-send-status-check-date'] = $this->_zTimeStamp; // обнулим время последнего запроса статуса
        return $this->_data['x-send-status'];
    }

    public function getDiff($data)
    {
        $this->buildAggregatedSettings();
        return array_diff_assoc($this->_aggrData, $data);
    }

    public function finish()
    {
        $this->_data['x-finished'] = 'true';
    }

    public function send()
    {
        global $_CFG;
        if ($this->_data['x-send-tries'] < $_CFG['X_SEND_TRIES_MAX']) {
            // сколько прошло времени с момента последней попытки отправки

            // Текущие дата и время
            $dtNow = date($this->_dtFormat);

            // Unixtime
            $tmNow = strtotime($dtNow);

            // Время последней отправки
            $tmLastSend = strtotime($this->_data['x-send-try-date']);

            // Интервал между последней отправкой
            $tmDiff = $tmNow - $tmLastSend;

            if ($tmDiff < $_CFG['X_SEND_TRIES_INTVL']) {
                // прошло меньше времени, чем заданный интервал отправки
                $this->setLastError("send interval not finished");
                return false;
            }

            // Пытаемся отправить сообщение
            $ret = $this->_smsSender->sendMessage($this->_data['number'], $this->_data['message']);

            //обновим время последней попытки отправки
            $this->_data['x-send-try-date'] = $dtNow;

            // ret == false
            if (!$ret) { // не удалось отправить сообщение(ошибка передачи сервису доставки)
                $this->_data['x-send-tries']++;
                $this->setLastError($this->_smsSender->getLastError()); // уточним ошибку
                return false;
            }

            // ['id' => '1', 'status' => 'queued'];
            $this->_data['x-msg-id'] = $ret['id'];
            $this->_data['x-send-status'] = $ret['status'];

            if ($this->_data['x-send-status'] == 'success') {     // сообщение было успешно доставлено
                $this->_data['x-tries-success']++;
            } elseif ($this->_data['x-send-status'] == 'error') { // сообщение не было успешно доставлено
                $this->_data['x-tries-error']++;
            }
        } else {
            $this->_data['x-send-tries'] = 0;
            $this->_data['x-tries-error']++;
            $this->_data['x-send-status'] = 'error';
        }
        // Обновим время последней отправки
        $this->_data['x-send-date'] = date($this->_dtFormat);
        $this->_data['x-tries-total']++; // Увеличиваем счетчик отправок
        $this->_data['x-send-try-date'] = $this->_zTimeStamp; // обнулим время последней попытки отправки

        return true;
    }

    public function getLastError()
    {
        return $this->_lastError;
    }

    private function setLastError($str)
    {
        $this->_lastError = $str;
    }

    // Получить аггрегированные настройки для номера(персональные настройки номера + настройки кампании(+ ...))
    private function buildAggregatedSettings()
    {
        $result = $this->_campSettings;

        // Настройки номера (переопределяют общие настройки кампании)
        // не используем array_merge, т.к. поля должны переопределяться
        foreach ($this->_data as $key => $val) {
            if (!isset($result[$key]) || $val != '') {
                $result[$key] = $val;
            }
        }
        $this->_aggrData = $result;
    }
}

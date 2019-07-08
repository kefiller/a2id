<?php

namespace local;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, PSR2.Classes.PropertyDeclaration.Underscore
class SMSSenderPik
{
    private $_proto = 'https';
    private $_hosts = ['api.smstraffic.ru', 'api2.smstraffic.ru'];
    private $_path  = 'multi.php';
    private $_lastError = '';
    private $_lastMsgID = '';
    private $_lastDeliveryStatus = '';

    private $_params = [
        'login' => 'pik-comfort:mayak',
        'password' => 'LightinInShadows75',
        'want_sms_ids' => '1',
        'max_parts' => '5',
        'rus' => '5',
//        'originator' => 'PIK-Comfort',
        'originator' => 'EIRC',
    ];

    // Отправляет сообщение, возвращает статус и ID( ['id' => $msgID, 'status' => $str] ) или false при ошибке
    public function sendMessage($number, $message)
    {
//        return ['id' => '1', 'status' => 'queued'];
//        return false;
        if ($number == '' || $message == '') {
            $this->_lastError = 'invalid arguments';
            return false;
        }

        // заменим 8 на 7 в начале номера
        $number = '7'.substr($number, 1);

        $this->_params['phones'] = $number;
        $this->_params['message'] = $message;

        $postParams = $this->buildPostParams($this->_params);

        $result = $this->request($postParams);

        if (!$result) {
            return false;
        } // lastError already set

        if (!isset($result->result) || !isset($result->code) || !isset($result->description)) {
            $this->_lastError = "incorrect answer: ".$result->asXML()."'";
            return false;
        }

        if ((string)$result->result != 'OK' || (int)$result->code > 0) {
            $this->_lastError = "error sending message: result = '".$result->result."', code='"
            .$result->code."', desc = '".$result->description."'";
            return false;
        }

        if (!isset($result->message_infos->message_info->sms_id)) {
            $this->_lastError = "could not get message id: ".$result->asXML()."'";
            return false;
        }

        $this->_lastMsgID = (string)$result->message_infos->message_info->sms_id;
        $this->_lastError = '';

        return ['id' => $this->_lastMsgID, 'status' => 'queued'];
    }

    // Возвращает статус сообщения (queued|success|error) или false при ошибке
    public function getMessageStatus($messageID)
    {
        if ($messageID == '') {
            $this->_lastError = 'invalid arguments';
            return false;
        }

        $params = [
            'login' => $this->_params['login'],
            'password' => $this->_params['password'],
            'operation' => 'status',
            'sms_id' => $messageID,
        ];

        $postParams = $this->buildPostParams($params);

        $result = $this->request($postParams);

        if (!$result) {
            return false;
        } // lastError already set

        if (!isset($result->status)) {
            $this->_lastError = "incorrect answer: '".$result->asXML()."'";
            return false;
        }

        //echo $result->asXML()."\n";
        $status = (string)$result->status;
        $this->_lastDeliveryStatus = $status;

        if ($status == 'Delivered') {
            return 'success';
        }
        if ($status == '' || $status == 'Buffered SMSC') {
            return 'queued';
        }

        out("Strange status: ".$status);

        return 'error';
    }

    public function getLastError()
    {
        return $this->_lastError;
    }

    public function getLastDeliveryStatus()
    {
        return $this->_lastDeliveryStatus;
    }

    // из массива формирует строку параметров для POST-запроса
    private function buildPostParams($arr)
    {
        $result = '';
        $amp = '';
        foreach ($arr as $k => $v) {
            $result .= $amp.$k."=".urlencode($v);
            $amp = '&';
        }
        return $result;
    }

    /*
    // Превращаем XML вида:
    <?xml version="1.0"?>
    <reply>
        <result>OK</result>
        <code>0</code>
        <description>queued 1 messages</description>
        <message_infos>
            <message_info>
                <phone>79190690067</phone>
                <sms_id>42262449310</sms_id>
            </message_info>
        </message_infos>
    </reply>

    // В объект типа массива:
    SimpleXMLElement Object
    (
        [result] => OK
        [code] => 0
        [description] => queued 1 messages
        [message_infos] => SimpleXMLElement Object
        (
            [message_info] => SimpleXMLElement Object
                (
                    [phone] => 79190690067
                    [sms_id] => 42262449310
                )
        )
    )
    */
    private function parseAnswer($xmlString)
    {
        if ($xmlString == '') {
            return false;
        }
        $xml = simplexml_load_string($xmlString);
        return $xml === false?false:$xml;
    }

    private function request($params)
    {
        $result = false;

        foreach ($this->_hosts as $host) {
            $url = $this->_proto.'://'.$host.'/'.$this->_path;

            $ret = $this->httpPost($url, $params);
            if ($ret == null) {
                continue;
            } // не получилось отправить

            $result = $this->parseAnswer($ret); // разберем ответ
            if (!$result) {
                continue;
            } // не получилось распарсить

            break;
        }

        if (!$result) {
            $this->_lastError = 'could not request any of servers';
            return false;
        }

        return $result;
    }

    private function httpPost($url, $params)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        // do not verify that ssl cert is valid (it is not the case for failover server)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, "MyUA 1.0");
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 seconds
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

        ob_start();
        $bSuccess=curl_exec($ch);
        $response=ob_get_contents();
        ob_end_clean();
        $http_result_code=curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($bSuccess && $http_result_code==200) ? $response : null;

        // end of cURL code
        ///////////////////////////////////////////////////////////////////////////////////////////////////
    }
}

<?php

namespace local;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, PSR2.Classes.PropertyDeclaration.Underscore
class MyDB
{
    /** @var mixed */
    private $_conn       = null;
    /** @var array */
    private $_dbServers  = [];
    /** @var array */
    private $_connParams = [];
    private $_quiet      = false;

    public function __construct($dbServers, $connParams, $quiet = false)
    {
        $this->_dbServers = $dbServers;
        $this->_connParams = $connParams;
        $this->_quiet = $quiet;
    }

    public function __destruct()
    {
        if ($this->_conn) {
            @pg_close($this->_conn);
        }
    }

    public function connect()
    {
        if (!is_array($this->_dbServers) || !is_array($this->_connParams)) {
            return false;
        }

        $this->_conn = false; // init new connection

        $dbname   = $this->_connParams['dbname'];
        $user     = $this->_connParams['user'];
        $password = $this->_connParams['password'];
        $connect_timeout = $this->_connParams['connect_timeout'];

        foreach ($this->_dbServers as $host => $port) {
            $pgConnString = "host=$host port=$port dbname=$dbname user=$user password=$password
             connect_timeout=$connect_timeout";
            if (!$this->_quiet) {
                out("DB: Connecting to $host:$port...");
            }
            //@phan-suppress-next-line PhanUndeclaredConstant
            $this->_conn = pg_connect($pgConnString, PGSQL_CONNECT_FORCE_NEW);
            if ($this->_conn == false) {
                if (!$this->_quiet) {
                    out("DB: could not connect to $host:$port");
                }
                continue;
            }
            if (!$this->_quiet) {
                out("DB: connected successfully to $host:$port");
            }
            $aRes = $this->query("select pg_is_in_recovery()", false);
            if (!isset($aRes[0]['pg_is_in_recovery'])) {
                if (!$this->_quiet) {
                    out("Empty result when asking for pg_is_in_recovery(), error: ".pg_last_error($this->_conn));
                }
                continue;
            }
            if ($aRes[0]['pg_is_in_recovery'] == 't') {
                if (!$this->_quiet) {
                    out("DB: $host:$port is in recovery mode");
                }
                $this->_conn = false;
                continue;
            }
            if (!$this->_quiet) {
                out("DB: Using $host:$port as primary server");
            }
            break;
        }
        if ($this->_conn == false) {
            if (!$this->_quiet) {
                out("DB: Could not find any suitable server, reconnecting in 5 seconds...");
            }
            sleep(5);
            return $this->connect();
        }
        return true;
    }

    public function query($sql, $bReconnectOnFailure = true)
    {
        $result = @pg_query($this->_conn, $sql);
        if (!$result) {
            $sErr = pg_last_error($this->_conn);
            if (strpos($sErr, 'syntax error') !== false) {
                $bReconnectOnFailure = false;
            }
            if (!$this->_quiet) {
                out("SQL ERROR: '$sErr', QUERY: '$sql'");
            }
            if ($bReconnectOnFailure) {
                if (!$this->_quiet) {
                    out("DB: reconnect db...");
                }
                $this->connect();
                sleep(1);
                return $this->query($sql);
            }
            return false;
        }
        $aResult = array();
        if (!pg_num_rows($result)) {
            return $aResult;
        }
        while ($row = pg_fetch_assoc($result)) {
            $aResult[]  = $row;
        }

        return $aResult;
    }

    // Сериализация массива данных
    public function serializeEntity(string $table, string $sType, array $aEntity)
    {
        foreach ($aEntity as $k => $v) {
            $sName = strtolower($k);
            $sJSON = json_encode($v, JSON_PRETTY_PRINT);
            $sQuery = "select count(*) from $table where s_type='$sType' and s_name = '$sName'";
            $aRet = $this->query($sQuery);
            $dCnt = 0;
            if (isset($aRet[0]['count'])) {
                $dCnt = $aRet[0]['count'];
            }
            if ($dCnt == 0) {
                $sQuery = "insert into $table(s_type,s_name,s_def) values('$sType','$sName','$sJSON')";
            } else {
                $sQuery = "update $table set s_def = '$sJSON' where s_type='$sType' and s_name = '$sName'";
            }
            $this->query($sQuery);
        }
        return true;
    }

    public function deserializeEntity($table, $sType, $sName = '')
    {
        $sQuery = "select s_name,s_def from $table where s_type='$sType'";
        if ($sName != '') {
            $sQuery .= " and s_name ='$sName'";
        }

        $aResult = [];
        $aRet = $this->query($sQuery);
        foreach ($aRet as $aRow) {
            if (isset($aRow['s_name'])) {
                $sName = strtolower($aRow['s_name']);
            }
            if (isset($aRow['s_def'])) {
                $sJSON = $aRow['s_def'];
            }
            if ($sName == '' || $sJSON == '') {
                continue;
            }
            $aDef = json_decode($sJSON, true);
            if ($aDef == null) {
                continue;
            }
            $aResult[$sName] = $aDef;
        }
        return $aResult;
    }

    public function tableExist($table)
    {
        $rslt = $this->query("SELECT to_regclass('public.$table') is NULL as exist");
        if (!isset($rslt[0]['exist'])) {
            return false;
        }

        return $rslt[0]['exist'] != 't';
    }
}

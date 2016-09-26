<?php
/**
 * User: Peter Wang
 * Date: 16/9/19
 * Time: 下午7:04
 */
namespace Trendi\Pool\Task;

class Pdo
{
    const CONN_MASTER = 0;
    const CONN_SLAVE = 1;

    private static $conn = [];
    private static $config = [];


    public static function setConfig($config)
    {
        self::$config = $config;
    }

    public static function getConfig()
    {
        return self::$config;
    }

    public function handle($sql, $dnType = self::CONN_MASTER, $method = "")
    {
        if (!$sql) return null;
        $conn = $this->setConn($dnType);
        if (!$method) {
            $conn->exec($sql);
            if ($conn->errorCode() != '00000') {
                $error = $conn->errorInfo();
                throw new \Exception('ERROR: [' . $error['1'] . '] ' . $error['2']);
            }
        } else {
            $result = $conn->query($sql);
            if ($conn->errorCode() != '00000') {
                $error = $conn->errorInfo();
                throw new \Exception('ERROR: [' . $error['1'] . '] ' . $error['2']);
            }
            return $result->$method();
        }
    }


    protected function setConn($dnType = self::CONN_MASTER)
    {
        if (self::$conn && isset(self::$conn[$dnType])) {
            return self::$conn[$dnType];
        }

        try {
            if (isset(self::$config['master']) && !isset(self::$conn[self::CONN_MASTER])) {
                $masterConfig = self::$config['master'];
                $dbh = new \PDO(self::$config['type'] . ':host=' . $masterConfig['host'] . ';port=' . $masterConfig['port'] . ';dbname=' . $masterConfig['db_name'] . '', $masterConfig['user'], $masterConfig['password'], array(\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\''));
                self::$conn[self::CONN_MASTER] = $dbh;
            }
            if (isset(self::$config['slave']) && !isset(self::$conn[self::CONN_MASTER])) {
                $masterConfig = self::$config['slave'];
                $slaveDBH = new \PDO(self::$config['type'] . ':host=' . $masterConfig['host'] . ';port=' . $masterConfig['port'] . ';dbname=' . $masterConfig['db_name'] . '', $masterConfig['user'], $masterConfig['password'], array(\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\''));
                self::$conn[self::CONN_SLAVE] = $slaveDBH;
            }

        } catch (\PDOException $e) {
            throw $e;
        }

        if (!isset(self::$conn[self::CONN_MASTER])) {
            throw new \PDOException('master database server must set ~');
        }

        if (!isset(self::$conn[self::CONN_SLAVE])) {
            self::$conn[self::CONN_SLAVE] = self::$conn[self::CONN_MASTER];
        }

        self::$conn[$dnType]->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        self::$conn[$dnType]->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return self::$conn[$dnType];
    }

    public function __destruct()
    {
    }
}

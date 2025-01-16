<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;
use App\Service\OCRestService;
use App\Service\Utilities;

class OpencastSeries
{

    protected static $table = "opencast_series_hash";
    protected static $checkUrlExists = true;

    /**
     * Default characters to use for shortening.
     *
     * @var string
     */
    private $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * Salt for id encoding.
     *
     * @var string
     */
    private $salt = 'series';

    /**
     * Length of number padding.
     */
    private $padding = 6;


    protected $dbh = null;

    private $series_id;

    public function __construct($series_id) {
        $this->series_id = $series_id;

        $this->connectLocally();
    }

    public function getDetails() {
        $details = ['hash' => $this->getHash(), 'series_id' => $this->series_id];
        return $details;
    }

    public function getHash() {
        $hash = $this->hashExistsInDb();
        if ($hash == false) {
            $hash = $this->createHashCode();
        }

        return $hash;
    }

    protected function hashExistsInDb() {
        $query = "SELECT short_code FROM " . self::$table .
            " WHERE series_id = :series_id LIMIT 1";
        $stmt = $this->dbh->prepare($query);
        $stmt->execute([ "series_id" => $this->series_id ]);

        $result = $stmt->fetch();
        return (empty($result)) ? false : $result["short_code"];
    }

    protected function createHashCode() {
        $id = $this->insertSeriesInDb($this->series_id);
        if ($id > 0) {
            $shortCode = 'S'.$this->convertIntToShortCode($id);
            $this->insertShortCodeInDb($id, $shortCode);
            return $shortCode;
        }
        return NULL;
    }

    protected function insertSeriesInDb($series_id) {
        $query = "INSERT INTO " . self::$table .
            " (series_id) " .
            " VALUES (:series_id) on duplicate key update series_id= :series_id";
        $stmnt = $this->dbh->prepare($query);
        if ($stmnt->execute([ ":series_id" => $series_id ]) === false) {
            throw new \Exception("insert ($query : $series_id) failed");
        }

        $query = "select id from ". self::$table ." where series_id= :series_id limit 1";
        $stmt = $this->dbh->prepare($query);
        $stmt->execute([ ":series_id" => $series_id ]);

        $oid = 0;
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $oid = $result[0]['id'];
        }
        return $oid;
    }

    /**
     * Gets a number for padding based on a salt.
     *
     * @param int $n Number to pad
     * @param string $salt Salt string
     * @param int $padding Padding length
     * @return int Number for padding
     */
    public static function get_seed($n, $salt, $padding) {
        $hash = md5($n.$salt);
        $dec = hexdec(substr($hash, 0, $padding));
        $num = $dec % pow(10, $padding);
        if ($num == 0) $num = 1;
        $num = str_pad($num, $padding, '0');
        return $num;
    }

    /**
     * Converts an id to an encoded string.
     *
     * @param int $n Number to encode
     * @return string Encoded string
     */
    public function convertIntToShortCode($n) {
        $k = 0;
        if ($this->padding > 0 && !empty($this->salt)) {
            $k = self::get_seed($n, $this->salt, $this->padding);
            $n = (int)($k.$n);
        }
        return self::num_to_alpha($n, $this->chars);
    }

    /**
     * Converts a number to an alpha-numeric string.
     *
     * @param int $num Number to convert
     * @param string $s String of characters for conversion
     * @return string Alpha-numeric string
     */
    public static function num_to_alpha($n, $s) {
        $b = strlen($s);
        $m = $n % $b;
        if ($n - $m == 0) return substr($s, $n, 1);
        $a = '';
        while ($m > 0 || $n > 0) {
            $a = substr($s, $m, 1).$a;
            $n = ($n - $m) / $b;
            $m = $n % $b;
        }
        return $a;
    }

    protected function insertShortCodeInDb($id, $code) {
        if ($id == null || $code == null) {
            throw new \Exception("Input parameter(s) invalid.");
        }
        $query = "UPDATE " . self::$table .
            " SET short_code = :short_code WHERE id = :id";
        $stmnt = $this->dbh->prepare($query);
        $params = array(
            "short_code" => $code,
            "id" => $id
        );
        $stmnt->execute($params);

        if ($stmnt->rowCount() < 1) {
            throw new \Exception("Row [". $id ."](". $code .") was not updated with short code.");
        }

        return true;
    }

    public function updateRetention($val, $updatedBy) {
        try {
            $query = "UPDATE opencast_series_hash SET updated_by = :user, retention = :retention where series_id = :series";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':user' => $updatedBy, ':retention' => $val, ':series' => $this->series_id]);
        } catch (\PDOException $e) {
            //throw new \Exception($e->getMessage());
            return false;
        }
        return true;
    }

    public function updateNotification($val, $updatedBy) {
        try {
            $query = "UPDATE opencast_series_hash SET updated_by = :user where series_id = :series";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':user' => $updatedBy, ':series' => $this->series_id]);
        } catch (\PDOException $e) {
            //throw new \Exception($e->getMessage());
            return false;
        }
        return true;
    }

    private function connectLocally() {
        $dotenv = new DotEnv();
        $dotenv->load('.env');

        $dbhost = getenv('DB_HOST');
        $dbname = getenv('DB_NAME');
        $dbuser = getenv('DB_USER');
        $dbpass = getenv('DB_PASS');
        $dbport = getenv('DB_PORT');
        $dbopts = [
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ];
        $this->dbh = new \PDO("mysql:host=$dbhost;dbname=$dbname;port=$dbport;charset=utf8mb4", $dbuser, $dbpass, $dbopts);
    }
}
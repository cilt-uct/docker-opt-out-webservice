<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;
use App\Service\Utilities;

class User
{
    private $dbh = null;

    private $ip;
    private $eid;
    private $email;
    private $first_name;
    private $last_name;
    private $status;

    public function __construct($eid, $ip = null, $search = null) {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        $this->eid = $eid;
        $this->ip = $ip;

        try {
            $args = [ ":eid" => $eid, ":search" => $search ];
            $query = "select * from timetable.view_sakai_users where (eid = :eid or eid = :search or email = :search) and status != 'test' limit 1";
            if ($eid == NULL) {
                $args = [":search" => $search ];
                $query = "select * from timetable.view_sakai_users where (eid = :search or email = :search) and status != 'test' limit 1";
            }

            $stmt = $this->dbh->prepare($query);
            $stmt->execute($args);
           if ($stmt->rowCount() > 0) {
                $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                $this->eid = $result[0]['eid'];
                $this->email = $result[0]['email'];
                $this->first_name = $result[0]['first_name'];
                $this->last_name = $result[0]['last_name'];
                $this->status = $result[0]['status'];
            } else {
                $this->status = $stmt->rowCount();
            }
        } catch (\PDOException $e) {
            throw new \Exception("no such user");
        }
    }

    public function getDetails() {
        if ($this->eid == NULL) {
            throw new \Exception("no such user");
        }
        return [
            'username' => $this->eid,
            'ip' => $this->ip,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'status' => $this->status
        ];
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

    public function __destruct() {
    }
}

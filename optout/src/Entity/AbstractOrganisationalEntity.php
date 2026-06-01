<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;

abstract class AbstractOrganisationalEntity implements OrganisationalEntityInterface
{

    private $dbh = null;

    private $entityCode;
    private $hash;
    private $year;
    private $skipCheck;

    private $fullHash;

    public function __construct($entityCode, $hash, $year = '', $skipCheck = false) {
        $this->entityCode = $entityCode;
        $this->hash = $hash;
        $this->year = !empty($year) ? $year : date('Y');
        $this->skipCheck = $skipCheck;

        $this->fetchDetails();
    }

    public function fetchDetails() {
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

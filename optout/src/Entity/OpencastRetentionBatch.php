<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\OpencastSeries;
use App\Service\OCRestService;
use App\Service\Utilities;

class OpencastRetentionBatch
{
    private $dbh = null;

    private $oid;
    private $status;
    private $active;
    /**
     * @Assert\DateTime()
     */
    private $date_last;
    /**
     * @Assert\DateTime()
     */
    private $date_start;
    /**
     * @Assert\DateTime()
     */
    private $date_scheduled;

    public function __construct($oid = -1) {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        try {

            $query = "select * from opencast_retention_batch where active = 1 limit 1";
            if ($oid > 0) {
                $query = "select * from opencast_retention_batch where id = :oid";
            }
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([":oid" => $oid]);

            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                $this->oid = $result[0]['id'];
                $this->status = $result[0]['status'];
                $this->date_last = new \DateTime($result[0]['date_last']);
                $this->date_start = new \DateTime($result[0]['date_start']);
                $this->date_scheduled = new \DateTime($result[0]['date_scheduled']);
                $this->active = ($result[0]['active'] ? true : false);
            } else {
                $this->status = $stmt->rowCount();
            }
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Get active Opencast Retention Batch
     */
    public function getBatch() {
        return [
            'oid' => $this->oid,
            'status' => $this->status,
            'date_last' => $this->date_last,
            'date_scheduled' => $this->date_scheduled,
            'active' => $this->active
        ];
    }

    /**
     * Run the batch
     */
    public function run() {

        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone('Africa/Johannesburg'));

        $result = [ 'success' => 1 ];
        $result['result'] = 'running: '. $this->status;

        //ENUM('created', 'waiting', 'running', 'completed')
        switch($this->status) {
            case 'created':
                // So initialization of this batch
                try {
                    $response = $this->startBatch();
                    $result['response'] = $response;
                    if ($response) {

                        // > state: waiting
                        $this->setState('waiting');
                        $result['result'] = $result['result'] ." - switch to waiting";
                    } else {
                        $result = [ 'success' => 0, 'err' => 'Error starting batch'];
                    }
                } catch (\PDOException $e) {
                    $result = [ 'success' => 0, 'result' => $e->getMessage()];
                }
                break;
            case 'waiting':
                // (wait for start date)

                if ($now->diff($this->date_start)->format('%R') == '-') {

                    $created = $this->createNotificationMails();
                    $result['result'] = $result['result'] ." ". json_encode($created);
                    if (($created['update'] == $created['mail']) && ($created['count'] == $created['mail'])) {

                        // > status : running
                        $this->setState('running');
                        $result['result'] = $result['result'] ." - switch to running";
                    } else {
                        $result = [ 'success' => 0, 'err' => 'Error creating mails : '. json_encode($created)];
                    }
                } else {
                    $result['result'] = $result['result'] ." - starts: ". $now->format("Y-m-d H:i:s");
                }
                break;
            case 'running':
                // (wait for scheduled date - run cleanup script to remove events from series)
                break;
            case 'completed':
                // All done
                break;
            default:
                $result = [ 'success' => 0, 'err' => 'Error running batch'];
                break;
        }

        return $result;
    }

    /**
     * Start the batch
     */
    private function startBatch(){
        if (!$this->dbh) {
            $this->connectLocally();
        }

        $utils = new Utilities();

        $all_done = FALSE;
        try {
            $qry = "select series,`series`.last_recording, `series`.retention, `hash`.batch, `hash`.action,
                        if(`series`.retention='normal', if(TIMESTAMPDIFF(YEAR,`series`.last_recording, :start_date ) >= 4, 1,0), 0) +
                        if(`series`.retention='long', if(TIMESTAMPDIFF(YEAR,`series`.last_recording, :start_date ) >= 8, 1,0), 0) as to_use,
                        TIMESTAMPDIFF(YEAR,`series`.last_recording, :start_date) as diff_years
                        from `timetable`.`view_oc_series` `series`
                        left join `timetable`.`opencast_series_hash` `hash` on `hash`.series_id = `series`.series
                        where (`series`.retention='normal' or `series`.retention='long')
                            and (`hash`.action='review' or `hash`.action='todo' or `hash`.action is null)
                            and DATEDIFF(`series`.last_recording, :last_date) <= 0
                        having to_use = 1
                        order by `series`.last_recording asc";

            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([':last_date' => $this->date_last->format("Y-m-d H:i:s"),
                            ':start_date' => $this->date_start->format("Y-01-01 00:00:00")]);
            if ($stmt->rowCount() === 0) {
                throw new \Exception("no series in this batch");
            }
            $series_in_batch = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($series_in_batch as $series) {

                $series_find = $utils->getSeriesById($series['series']);

                if (!$series_find['success']) {
                    throw new \Exception("finding series error");
                }
                $series_data = $series_find['result'][0];

                $ocService = new OCRestService();
                $metadata = $ocService->getSeriesMetadata($series['series']);
                foreach($metadata as $struct) {
                    $tmp = [];
                    foreach($struct['fields'] as $field) {
                        $tmp[ str_replace("-","_",$field['id'])] = $field['value'];
                    }
                    switch ($struct['flavor']) {
                        case 'dublincore/series':
                            $series_data['dublincore'] = $tmp;
                            break;
                        case 'ext/series':
                            $series_data['ext'] = $tmp;
                            break;
                    }
                }

                $active = 0;
                $no_recordings = 0;
                if (isset($series_data['no_recordings'])) {
                    $active = ($series_data['no_recordings'] == 0 ? 0 : 1);
                    $no_recordings = $series_data['no_recordings'];
                }

                if ($series_data['username'] != "") {
                    $series_data['user'] = (new User($series_data['username']))->getDetails();
                }

                if (isset($series_data['ext'])) {
                    if (isset($series_data['ext']['creator_id'])) {
                        if ($series_data['ext']['creator_id'] != "") {
                            $series_data['user'] = (new User($series_data['ext']['creator_id']))->getDetails();
                        }
                    }
                }

                $status = 'error';
                $user_status = 'Not Set';
                if (isset($series_data['user']['status'])) {
                    $user_status = $series_data['user']['status'];
                    switch($series_data['user']['status']) {
                        case 'admin':
                        case 'guest':
                        case 'staff':
                        case 'student':
                        case 'associate':
                        case 'special':
                        case 'thirdparty':
                        case 'user':
                            $status = 'ready';
                            break;
                        case 'Inactive':
                        case 'inactiveStaff':
                        case 'inactiveStudent':
                        case 'inactiveThirdparty':
                        case 'offer':
                        case 'pace':
                        case 'test':
                        case 'webctImport':
                            $status = 'error';
                        break;
                        default:
                            $status = 'error';
                        break;
                    }
                }

                $updateQry = "INSERT INTO `opencast_series_hash`
                (`series_id`, `batch`, `action`, `active`, `user_status`, `no_recordings`) VALUES (:series, :batch, :status, :active, :user_status, :no_recordings)
                on duplicate key update series_id= :series, batch = :batch, action = :status, active = :active, user_status = :user_status, no_recordings = :no_recordings";

                try {
                    $updateStmt = $this->dbh->prepare($updateQry);
                    $bind = [':series' => $series['series'],
                             ':batch' => $this->oid,
                             ':active' => $active,
                             ':status' => $no_recordings == 0 ? 'empty' : $status,
                             ':user_status' => $user_status,
                             ':no_recordings' => $no_recordings
                            ];
                    if ($updateStmt->execute($bind) === false){
                        throw new \Exception('conflict ['. $updateQry .']'. json_encode($bind));
                    }
                } catch (\PDOException $e) {
                    throw new \Exception($e->getMessage());
                }
                $all_done = TRUE;
            }

            $qry = "select series_id from `timetable`.`opencast_series_hash` `hash` where `hash`.short_code is null and `hash`.batch = :batch";
            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([':batch' => $this->oid]);
            $series_in_batch = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($series_in_batch as $series) {
                $h = new OpencastSeries($series['series_id']);
                if ($h->getHash() == NULL) {
                    $all_done = FALSE;
                }
            }

        } catch (\PDOException $e) {
            return $e->getMessage();
        }
        return $all_done;
    }

    private function createNotificationMails(){

        $done = [ 'count' => 0, 'update' => 0, 'mail' => 0];
        $utils = new Utilities();
        try {
            // get list of series in the first batch
            $query = "select `hash`.series_id, `hash`.active, `series`.title, `hash`.short_code as 'hash',
                    `series`.username, `series`.retention, `hash`.batch, `series`.last_recording, `series`.count as 'no_recordings'
                    from `timetable`.`opencast_series_hash` `hash`
                        left join `timetable`.`view_oc_series` `series` on `hash`.series_id = `series`.series
                    where `hash`.`batch` = :batch and `hash`.`action` = 'ready'
                        and `hash`.short_code not in (select `hash` from opencast_retention_email `mail` where `mail`.`type` = 'notification')";

            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':batch' => $this->oid]);

            while ($series = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $ocService = new OCRestService();
                $metadata = $ocService->getSeriesMetadata($series['series_id']);
                foreach($metadata as $struct) {
                    $tmp = [];
                    foreach($struct['fields'] as $field) {
                        $tmp[ str_replace("-","_",$field['id'])] = $field['value'];
                    }
                    switch ($struct['flavor']) {
                        case 'dublincore/series':
                            $series['dublincore'] = $tmp;
                            break;
                        case 'ext/series':
                            $series['ext'] = $tmp;
                            break;
                    }
                }

                // Now to see if the users are still active or not
                if ($series['username'] != "") {
                    $series['user'] = (new User($series['username']))->getDetails();
                }

                if ($series['ext']['series_expiry_date'] == "") {
                    $year_adjust = $series['ext']['retention_cycle'] == 'forever' ? 50 : (($series['ext']['retention_cycle'] == 'normal' ? 4 : 8));

                    $dt = new \DateTime($series['last_recording']);
                    $dt->modify('+'. $year_adjust  .' years');
                    $data['date'] = $series['ext']['retention_cycle'] == 'forever' ? 'forever' : $dt->format("Y-m-d");

                    $ocService->updateRetention($series['series_id'], $series['ext']['retention_cycle'], $dt->format("Y-m-d") ."T00:00:00.000Z", 'system');
                }

                $done['update'] += (new OpencastSeries($series['series_id']))->updateRetention($series['ext']['retention_cycle'], 'system') ? 1 : 0;
                $done['mail'] += $utils->addSeriesEmails($series['series_id'], $series['hash'], $series['batch'],
                                                            $series['user']['first_name'] .' '. $series['user']['last_name'],
                                                            $series['user']['email'],  implode(';', $series['ext']['notification_list'])) ? 1 : 0;
            }
            $done['count'] = $stmt->rowCount();
            $stmt = null;

        } catch (\PDOException $e) {
            return $e->getMessage();
        }

        return $done;
    }

    private function setState($status) {
        try {
            $query = "UPDATE opencast_retention_batch SET status = :status WHERE id = :oid";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':status' => $status, ':oid' => $this->oid]);
            $this->status = $status;
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        }
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
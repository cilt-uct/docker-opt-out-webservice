<?php

namespace App\Service;

use Symfony\Component\Dotenv\Dotenv;
use App\Service\SakaiWebService;
use App\Service\OCRestService;
use App\Entity\HashableInterface;
use App\Entity\Course;
use App\Entity\Department;
use App\Entity\Workflow;
use App\Entity\User;
use App\Entity\OpencastSeries;

class Utilities
{
    private $dbh;

    public function __construct($dbh = null) {
        if (is_null($dbh)) {
            $this->connectLocally();
        }
        else {
            $this->dbh = $dbh;
        }
    }

    public function refreshCourses() {
        try {
            $workflow = (new Workflow)->getWorkflow();

            $qry = "select distinct `sn`.course_code, `ps`.term, `ps`.dept,
                    if(`sn`.course_code REGEXP '". Course::SEM1 ."', 's1', if(`sn`.course_code REGEXP '". Course::SEM2 ."', 's2', 's0')) as 'semester',
                    (select id from timetable.uct_workflow `w` where `w`.year = :year and `w`.semester = if(`sn`.course_code REGEXP '". Course::SEM1 ."', 's1', if(`sn`.course_code REGEXP '". Course::SEM2 ."', 's2', 's0'))) as workflow_id
                    FROM timetable.sn_timetable_versioned `sn`
                        inner join opencast_venues  `venue`  on `sn`.archibus_id =  `venue`.archibus_id
                        inner join ps_courses `ps` on `sn`.course_code = `ps`.course_code and `sn`.term = `ps`.term
                    WHERE
                        `sn`.term = :year
                        and `ps`.active = 1
                        and `ps`.acad_career = 'UGRD'
                        and `venue`.campus_code in (". Course::ELIGIBLE .")
                        and `sn`.instruction_type='Lecture'
                        and `sn`.course_code not in (select course_code from course_optout where year = :year)

                        order by `sn`.course_code";

            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([
                ':year' => date('Y'),
            ]);

            $optoutQry = "insert into course_optout (course_code, year, dept, semester, workflow_id) values (:course, :year, :dept, semester, :workflow_id) on duplicate key update course_code = :course, dept = :dept, workflow_id = :workflow_id";
            $optoutStmt = $this->dbh->prepare($optoutQry);

            $updateResults = [
                'coursesFound' => $stmt->rowCount(),
                'coursesUpdated' => 0
            ];

            if ($stmt->rowCount() > 0) {
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    if ($row['workflow_id']) {
                        $optoutStmt->execute([
                            ':course' => $row['course_code'],
                            ':year' => $row['term'],
                            ':dept' => $row['dept'],
                            ':semester' => $row['semester'],
                            ':workflow_id' => $row['workflow_id']
                        ]);
                        $updateResults['coursesUpdated'] += $optoutStmt->rowCount();
                    }
                }
                //$this->updateVulaSites(); TODO
                //$this->updateOCSeries();  TODO
            }

            return $updateResults;
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function refreshDepartments() {
        /*
        UPDATE timetable.uct_dept t1
            INNER JOIN timetable.uct_dept t2 ON t2.dept = t1.dept
            SET t1.hod_eid = if (getUserEIDFromMail(t1.email) REGEXP '^-?[0-9]{8}$' > 0, getUserEIDFromMail(t1.email),  getUserEIDFromMail(SUBSTRING_INDEX(t1.alt_email, ',',1)))
        */
        try {
            $workflow = (new Workflow)->getWorkflow();

            $qry = "";

            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([
                ':year' => date('Y'),
                ':this_year_half' => $yearHalf
            ]);

            $updateResults = [
                'coursesFound' => $stmt->rowCount(),
                'coursesUpdated' => 0
            ];

            if ($stmt->rowCount() > 0) {
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $optoutStmt->execute([
                        ':course' => $row['course_code'],
                        ':year' => $row['term'],
                        ':dept' => $row['dept'],
                        ':workflow_id' => $workflow['oid']
                    ]);
                    $updateResults['coursesUpdated'] += $optoutStmt->rowCount();
                }
            }

            return $updateResults;
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function userVisibleHash(HashableInterface $hashable) {
        return substr($hashable->getFullHash(), 0, 6);
    }

    public function getMail($hash) {
        $workflow = new Workflow();
        $worfklow_details = $workflow->getWorkflow();

        // Get real course/dept hash - select first in current batch
        if ($this->startsWith($hash,'zzzc') || $this->startsWith($hash,'zzzd')) {
            $hash = $this->getOptOutTestHash($worfklow_details['oid'], $this->startsWith($hash,'zzzc'));
        }

        $result = [ 'success' => 1, 'result' => null ];
        try {
            $query = "select mail.dept, mail.course, mail.state, mail.created_at, mail.name, mail.type, mail.case, :hash as hash,
                        `workflow`.`year`, `workflow`.`status`, `workflow`.`date_start`, `workflow`.`date_dept`, `workflow`.`date_course`, `workflow`.`date_schedule`
                        from uct_workflow_email mail
                        left join `uct_workflow` `workflow` on `mail`.`workflow_id` = `workflow`.`id`
                        where `workflow`.`year` = :year and hash = :hash order by created_at desc limit 1"; // and workflow_id = :workflow_id
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':hash' => $hash, ':year' => $worfklow_details['year']]); // ':workflow_id' => $worfklow_details['oid']
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'The reference was not found, please contact <a href="mailto:cilt-helpdesk@uct.ac.za?subject=Automated Setup of Lecture Recording (REF: '.$hash.')&body=Hi Vula Help Team,%0D%0A%0D%0AThe view page with the reference ('.$hash.') returns an error.%0D%0A%0D%0APlease fix this and get back to me.%0D%0A%0D%0AThanks you,%0D%0A" title="Cilt Help Desk">cilt-helpdesk@uct.ac.za</a>.'];
            }

            $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $result;
    }

    public function getAllCourses($year = '') {

        $workflow = new Workflow();
        $worfklow_details = $workflow->getWorkflow();

        $result = [ 'success' => true, 'result' => null ];
        try {
            $query = "select A.*, A.hod_eid as eid, B.*,
                    (SELECT count(distinct(`ps`.course_code))
                        FROM timetable.ps_courses `ps`
                            join timetable.course_optout `out` on `ps`.course_code = `out`.course_code and `ps`.term = `out`.year
                            left join timetable.sn_timetable_versioned `sn` on `sn`.course_code = `ps`.course_code and `sn`.term = `ps`.term
                            left join timetable.opencast_venues `venue` on `sn`.archibus_id =  `venue` .archibus_id
                        WHERE
                            `ps`.dept = A.dept and `ps`.term = B.year
                            and `ps`.active = 1
                            and `ps`.acad_career = 'UGRD'
                            and  `venue` .campus_code in (". Course::ELIGIBLE .")
                            and `sn`.instruction_type='Lecture'
                            and `ps`.course_code REGEXP '". Course::SEM1 ."'
						having (max(`sn`.tt_version)) ) as eligble_courses_S1,
                    (SELECT count(distinct(`ps`.course_code))
                        FROM timetable.ps_courses `ps`
                            join timetable.course_optout `out` on `ps`.course_code = `out`.course_code and `ps`.term = `out`.year
                            left join timetable.sn_timetable_versioned `sn` on `sn`.course_code = `ps`.course_code and `sn`.term = `ps`.term
                            left join timetable.opencast_venues `venue` on `sn`.archibus_id =  `venue` .archibus_id
                        WHERE
                            `ps`.dept = A.dept and `ps`.term = B.year
                            and `ps`.active = 1
                            and `ps`.acad_career = 'UGRD'
                            and  `venue` .campus_code in (". Course::ELIGIBLE .")
                            and `sn`.instruction_type='Lecture'
                            and `ps`.course_code REGEXP '". Course::SEM2 ."'
						having (max(`sn`.tt_version)) ) as eligble_courses_S2,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 0 and course REGEXP '". Course::SEM1 ."') as s1_mail_unsent,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 0 and course REGEXP '". Course::SEM1 ."') as s1_mail_unsent,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 1 and `type` = 'notification' and course REGEXP '". Course::SEM1 ."') as s1_mail_sent_note,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 1 and `type` = 'confirm' and course REGEXP '". Course::SEM1 ."') as s1_mail_sent_confirm,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 2 and course REGEXP '". Course::SEM1 ."') as s1_mail_err,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 0 and course REGEXP '". Course::SEM2 ."') as s2_mail_unsent,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 1 and `type` = 'notification' and course REGEXP '". Course::SEM2 ."') as s2_mail_sent_note,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 1 and `type` = 'confirm' and course REGEXP '". Course::SEM2 ."') as s2_mail_sent_confirm,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 2 and course REGEXP '". Course::SEM2 ."') as s2_mail_err
                from timetable.uct_dept A left join timetable.dept_optout B on A.dept = B.dept
                where B.year = :year and A.exists = 1";

            $stmt = $this->dbh->prepare($query);

            if ($stmt->execute([':year' => $worfklow_details['year'], ':workflow_id' => $worfklow_details['oid']])) {
                if ($stmt->rowCount() === 0) {
                    $result = [ 'success' => false, 'err' => 'Query is empty', ':year' => $worfklow_details['year'], ':workflow_id' => $worfklow_details['oid']];
                }
            } else {
                $result = [ 'success' => false, 'err' => $stmt->errorInfo(), ':year' => $worfklow_details['year'], ':workflow_id' => $worfklow_details['oid']];
            }

            # dept, name, email, firstname, lastname, alt_email, use_dept, secret, year, is_optout
            $ar = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $d = new Department($row['dept'], null, $worfklow_details['year'], true);
                $dept = $row;
                $dept['hash'] = $d->getHash();
                unset($dept['secret']);
                $dept['mails'] = $this->getDepartmentMails($worfklow_details['oid'], $row['dept']);
                array_push($ar, $dept);
            }
            $result['result'] = $ar;
        } catch (\PDOException $e) {
            $result = [ 'success' => false, 'err' => $e->getMessage()];
        }

        return $result;
    }

    private function getDepartmentMails($workflow_id, $dept) {
        $result = [ 'success' => 1, 'result' => null ];
        try {
            $query = "select * from uct_workflow_email where workflow_id = :workflow_id and dept = :dept and course is null";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':workflow_id' => $workflow_id, ':dept' => $dept]);
            if ($stmt->rowCount() === 0) {
                $result = [ 'success' => 0 ];
            }

            $ar = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                array_push($ar, $row);
            }

            $result['result'] = $ar;
        } catch (\PDOException $e) {
            $result = [ 'success' => 0 ];
        }

        return $result;
    }

    public function getAuthorizedUsers($username) {
        $result = [ 'success' => 0, 'err' => 'Unauthorized ('.$username.')'];
        try {
            $query = "select `user`.`username`, `user`.`name`, `user`.`type`
                        from uct_authorized_users `user`
                        where `user`.`username` = :username limit 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':username' => $username]);
            if ($stmt->rowCount() === 0) {
                $result['err'] = 'Unauthorized ('.$username.')';
            } else {
                $result['success'] = 1;
                $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                unset($result['err']);
            }
        } catch (\PDOException $e) {
            $result['err'] = $e->getMessage();
        }

        return $result;
    }

    public function getAllBatches() {
        $result = [ 'success' => 1, 'result' => null ];
        try {
            $query = "SELECT `id`, `status`, `date_last`, `date_start`, `date_scheduled`,`active` FROM timetable.opencast_retention_batch;";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute();
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'No Batches found'];
            }

            $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $result;
    }

    private function getSeriesCount($where = "", $arg = "") {
        $result = 0;
        $q = $this->dbh->prepare("select count(*) as cnt from `timetable`.`view_oc_series` `series`
            left join `timetable`.`opencast_series_hash` `hash` on `hash`.series_id = `series`.series
            left join `timetable`.`view_sakai_users` `user` on `user`.`eid` = `series`.username and `user`.status != 'test'
            $where");
        if ($q->execute($arg)) {
            $f = $q->fetch();
            $result = $f['cnt'];
        }
        return $result;
    }

    private function getSeriesCustomCount($val = "normal", $where = "", $a = "", $st = " `series`.`retention`=:v") {
        $result = 0;
        $w = $where = ($where == "" ? " where ": $where ." and ") . $st;
        $a[':v'] = $val;
        $q = $this->dbh->prepare("select count(*) as cnt from `timetable`.`view_oc_series` `series`
            left join `timetable`.`opencast_series_hash` `hash` on `hash`.series_id = `series`.series
            left join `timetable`.`view_sakai_users` `user` on `user`.`eid` = `series`.username and `user`.status != 'test'
            $where");
        if ($q->execute($a)) {
            $f = $q->fetch();
            $result = $f['cnt'];
        }
        return $result;
    }

    public function getAllSeries($offset = 0, $limit = 15, $sort_dir = 'asc', $sort_field = 'title', $filter = "", $ret = "all", $batch = 0, $act = "none") {

        $result = [ 'success' => true,
                    'result' => null,
                    "offset" => $offset,
                    "limit" => $limit,
                    "filter" =>  $filter,
                    "order" => $sort_field .",". $sort_dir,
                    "ret" => $ret,
                    "batch" => $batch,
                    "all" => 0, "normal" => 0, "long" => 0, "forever" => 0,
                    "total" => 0, "count" => 0
                ];
        try {
            $where = '';
            $arg = [];
            $query = "select `series`.id, `series`.series, `series`.title,
                        `series`.contributor, `series`.creator,
                        `series`.username, `user`.first_name, `user`.last_name,
                        `hash`.user_status,
                        `series`.created_date, `series`.first_recording, `series`.last_recording,
                        `series`.`count`, `series`.`archive_count`,
                        `series`.`retention`, `hash`.`retention` as hash_retention,
                        `series`.`modification_date`, `hash`.`updated_at` as hash_modification_date,
                        `hash`.batch, `hash`.active, `hash`.action,
                        (select count(*) from `timetable`.`opencast_retention_email` `email` where `email`.hash = `hash`.short_code) as 'mail_count'
                        from `timetable`.`view_oc_series` `series`
                        left join `timetable`.`opencast_series_hash` `hash` on `hash`.series_id = `series`.series
                        left join `timetable`.`view_sakai_users` `user` on `user`.`eid` = `series`.username and `user`.status != 'test'";
            if ($filter != "") {
                $where = " where (`series`.title like :text or `series`.username like :text or concat_ws(' ',`user`.first_name,`user`.last_name) like :text)";
                $arg[":text"] = '%'. $filter .'%';
            }
            if ($batch != 0) {
                $where = ($where == "" ? " where ": $where ." and ") ." `hash`.batch=:batch";
                $arg[":batch"] = $batch;
            }

            $result['all'] = $this->getSeriesCount($where, $arg);
            $result['normal'] = $this->getSeriesCustomCount("normal", $where, $arg);
            $result['long'] = $this->getSeriesCustomCount("long", $where, $arg);
            $result['forever'] = $this->getSeriesCustomCount("forever", $where, $arg);

            if ($ret != "all") {
                $where = ($where == "" ? " where ": $where ." and ") ." `series`.`retention`=:ret";
                $arg[":ret"] = $ret;
            }

            $result['action'] = $act;
            $result['state_ready'] = $this->getSeriesCustomCount("ready", $where, $arg, " `hash`.action=:v and `hash`.batch >= 1");
            $result['state_review'] = $this->getSeriesCustomCount("review", $where, $arg, " `hash`.action=:v and `hash`.batch >= 1");
            $result['state_done']  = $this->getSeriesCustomCount("done", $where, $arg, " `hash`.action=:v and `hash`.batch >= 1");
            $result['state_error'] = $this->getSeriesCustomCount("error", $where, $arg, " `hash`.action=:v and `hash`.batch >= 1");
            $result['state_empty'] = $this->getSeriesCustomCount("empty", $where, $arg, " `hash`.action=:v and `hash`.batch >= 1");

            if ($act != "none") {
                $where = ($where == "" ? " where ": $where ." and ") ." `hash`.action=:act and `hash`.batch >= 1";
                $arg[":act"] = $act;
            }

            switch ($sort_field) {
                case 'retention': $sort_field = 'retention'; break;
                case 'organizer': $sort_field = 'contributor'; break;
                case 'events': $sort_field = 'count'; break;
            }

            $query .= $where . " order by $sort_field $sort_dir LIMIT $limit OFFSET $offset";

            $result['query'] = str_replace("\n","",$query);
            $result['where'] = $where;
            $result['arg'] = $arg;

            $stmt = $this->dbh->prepare($query);
            if ($stmt->execute($arg)) {
                if ($stmt->rowCount() === 0) {
                    $result['success'] = false;
                    $result['err'] = 'Query is empty';
                }

                $ar = [];
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                    $r = $row;
                    $oc_series = new OpencastSeries($row['series']);
                    $r['hash'] = $oc_series->getHash();
                    array_push($ar, $r);
                }
                $result['total'] = $this->getSeriesCount($where, $arg);
                $result['count'] = $stmt->rowCount();
                $result['result'] = $ar;
            } else {
                $result['success'] = false;
                $result['err'] = $stmt->errorInfo();
            }
        } catch (\PDOException $e) {
            $result['success'] = false;
            $result['err'] = $e->getMessage();
        }

        return $result;
    }

    public function getSeries($hash) {

        // Get real series hash - select first in current batch
        // if ($this->startsWith($hash,'zzzc') || $this->startsWith($hash,'zzzd')) {
        //     $hash = $this->getRetentionTestHash($worfklow_details['oid'], $this->startsWith($hash,'zzzc'));
        // }

        $result = [ 'success' => 1, 'result' => null ];
        try {
            $query = "select `hash`.series_id, `hash`.active, `series`.title, `series`.contributor, `series`.creator, `hash`.`action`,
                        `series`.username, `series`.retention, `hash`.batch, `series`.last_recording, `series`.count as 'no_recordings', :hash as `hash`
                        from `timetable`.`opencast_series_hash` `hash`
                        left join `timetable`.`view_oc_series` `series` on `hash`.series_id = `series`.series
                        where `hash`.short_code = :hash order by created_at desc limit 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':hash' => $hash]);
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'The reference was not found, please contact <a href="mailto:cilt-helpdesk@uct.ac.za?subject=Series Details (REF: '.$hash.')&body=Hi Vula Help Team,%0D%0A%0D%0AThe view page with the reference ('.$hash.') returns an error.%0D%0A%0D%0APlease fix this and get back to me.%0D%0A%0D%0AThanks you,%0D%0A" title="Cilt Help Desk">cilt-helpdesk@uct.ac.za</a>.'];
            }

            $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $result;
    }

    public function getSeriesById($series_id) {
        $result = [ 'success' => 1, 'result' => null ];
        try {
            $query = "select `hash`.series_id, `hash`.active, `series`.title, `series`.contributor, `series`.creator,
                        `series`.username, `series`.retention, `hash`.batch, `series`.last_recording, `series`.count as 'no_recordings'
                        from `timetable`.`view_oc_series` `series`
                        left join `timetable`.`opencast_series_hash` `hash` on `hash`.series_id = `series`.series
                        where `series`.series = :series_id order by created_at desc limit 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':series_id' => $series_id]);
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'The reference was not found, please contact <a href="mailto:cilt-helpdesk@uct.ac.za?subject=Series Details (REF: '.$series_id.')&body=Hi Vula Help Team,%0D%0A%0D%0AThe view page with the reference ('.$series_id.') returns an error.%0D%0A%0D%0APlease fix this and get back to me.%0D%0A%0D%0AThanks you,%0D%0A" title="Cilt Help Desk">cilt-helpdesk@uct.ac.za</a>.'];
            }

            $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $result;
    }

    public function getSeriesEmails($hash) {
        $result = [ 'success' => 1, 'result' => null ];
        try {
            $qry = "SELECT `state`, `updated_at` as `sent`,`type`,`case`,`hash` FROM timetable.opencast_retention_email
                    where hash = :hash";
            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([':hash' => $hash]);

            $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $result;
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $result;
    }

    public function addSeriesEmails($series_id, $hash, $batch_id, $name, $mail_to, $mail_cc){

        $insertQry = "insert into opencast_retention_email (batch_id, series_id, hash, name, mail_to, mail_cc)
                        VALUES (:batch_id, :series_id, :hash, :name, :mail_to, :mail_cc)";

        try {
            $insertStmt = $this->dbh->prepare($insertQry);
            $bind = [
                ':batch_id' => $batch_id,
                ':series_id' => $series_id,
                ':hash' => $hash,
                ':name' => $name,
                ':mail_to' => $mail_to,
                ':mail_cc' => $mail_cc
            ];
            $insertStmt->execute($bind);
            if ($insertStmt->rowCount() === 0) {
                return FALSE;
            }
        } catch (\PDOException $e) {
            return 'ERR:'. $e->getMessage();
        }

        return TRUE;
    }

    public function getLastNotificationRetentionEmail($hash) {
        $result = '';
        try {
            $qry = "SELECT updated_at FROM timetable.opencast_retention_email where `hash` = :hash and `type`='notification' limit 1";
            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([':hash' => $hash]);

            $r = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $r[0]['updated_at'];
        } catch (\PDOException $e) {
            $result = '';
        }

        return $result;
    }

    public function getOptOutTestHash($workflow_id, $is_course = FALSE) {
        try {
            $query = "select `hash` from uct_workflow_email where course is null and workflow_id = :workflow_id and `hash` NOT LIKE 'zzz%' order by id limit 1";
            if ($is_course) {
                $query = "select `hash` from uct_workflow_email where course is not null and  workflow_id = :workflow_id and `hash` NOT LIKE 'zzz%' order by id limit 1";
            }
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':workflow_id' => $workflow_id]);

            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                return $result[0]['hash'];
            } else {
                return NULL;
            }
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    // For Series details
    public function getRetentionTestHash($batch_id) {
        // try {
        //     $query = "select `hash` from uct_workflow_email where course is null and workflow_id = :workflow_id and `hash` NOT LIKE 'zzz%' limit 1";
        //     if ($is_course) {
        //         $query = "select `hash` from uct_workflow_email where course is not null and  workflow_id = :workflow_id and `hash` NOT LIKE 'zzz%' limit 1";
        //     }
        //     $stmt = $this->dbh->prepare($query);
        //     $stmt->execute([':workflow_id' => $workflow_id]);

        //     if ($stmt->rowCount() > 0) {
        //         $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        //         return $result[0]['hash'];
        //     } else {
        //         return NULL;
        //     }
        // } catch (\PDOException $e) {
        //     throw new \Exception($e->getMessage());
        // }
    }

    private function getResultEmailsCount($where = "", $arg = "") {
        $result = 0;
        $q = $this->dbh->prepare("select count(*) as cnt FROM timetable.results_notification_emails $where");
        if ($q->execute($arg)) {
            $f = $q->fetch();
            $result = $f['cnt'];
        }
        return $result;
    }

    private function getResultEmailsCustomCount($val = "faculty", $where = "", $a = "", $st = "`type`=:v") {
        $result = 0;
        $w = $where = ($where == "" ? " where ": $where ." and ") . $st;
        $a[':v'] = $val;
        $q = $this->dbh->prepare("select count(*) as cnt FROM timetable.results_notification_emails $where");
        if ($q->execute($a)) {
            $f = $q->fetch();
            $result = $f['cnt'];
        }
        return $result;
    }

    public function getResultEmails($offset = 0, $limit = 15, $sort_dir = 'asc', $sort_field = 'title', $filter = "", $type = "all", $state = "all") {

        $result = [ 'success' => true,
                    'result' => null,
                    "offset" => $offset,
                    "limit" => $limit,
                    "filter" =>  $filter,
                    "order" => $sort_field .",". $sort_dir,
                    "type" => $type,
                    "state" => $state,
                    "total" => 0, "count" => 0
                ];
        try {
            $where = '';
            $arg = [];
            $query = "SELECT `updated_at`, `name` as mail_name, mail_to, `hash`, `state`, `type`, `code` FROM timetable.results_notification_emails";
            if ($filter != "") {
                $where = " where (`code` like :text or `name` like :text or mail_to like :text)";
                $arg[":text"] = '%'. $filter .'%';
            }

            $result['cnt_all'] = $this->getResultEmailsCount($where, $arg);
            $result['cnt_faculty'] = $this->getResultEmailsCustomCount("faculty", $where, $arg, " `type`=:v");
            $result['cnt_dept'] = $this->getResultEmailsCustomCount("dept", $where, $arg, " `type`=:v");
            $result['cnt_course'] = $this->getResultEmailsCustomCount("course", $where, $arg, " `type`=:v");

            if ($type != "all") {
                $where = ($where == "" ? " where ": $where ." and ") ." `type`=:type";
                $arg[":type"] = $type;
            }

            $result['cnt_0'] = $this->getResultEmailsCustomCount("0", $where, $arg, " `state`=:v");
            $result['cnt_1'] = $this->getResultEmailsCustomCount("1", $where, $arg, " `state`=:v");
            $result['cnt_2']  = $this->getResultEmailsCustomCount("2", $where, $arg, " `state`=:v");
            $result['cnt_3'] = $this->getResultEmailsCustomCount("3", $where, $arg, " `state`=:v");

            if ($state != "all") {
                $where = ($where == "" ? " where ": $where ." and ") ." `state`=:state";
                $arg[":state"] = $state;
            }

            switch ($sort_field) {
                case 'convener': $sort_field = 'mail_to'; break;
            }

            $query .= $where . " order by $sort_field $sort_dir LIMIT $limit OFFSET $offset";

            $result['query'] = str_replace("\n","",$query);
            $result['where'] = $where;
            $result['arg'] = $arg;

            $stmt = $this->dbh->prepare($query);
            if ($stmt->execute($arg)) {
                if ($stmt->rowCount() === 0) {
                    $result['success'] = false;
                    $result['err'] = 'Query is empty';
                }

                $result['total'] = $this->getSeriesCount($where, $arg);
                $result['count'] = $stmt->rowCount();
                $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } else {
                $result['success'] = false;
                $result['err'] = $stmt->errorInfo();
            }
        } catch (\PDOException $e) {
            $result['success'] = false;
            $result['err'] = $e->getMessage();
        }

        return $result;
    }

    public function getSurveyResultsExec($args, $query) {
        $result = [];
        try {
            $stmt = $this->dbh->prepare($query);
            $stmt->execute($args);
            if ($stmt->rowCount() === 0) {
                $result['success'] = 0;
                $result['err'] = 'cohort_response';
            }

            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = $e->getMessage();
        }
        return $result;
    }

    public function getSurveyResults($in_hash) {

        $hash = $this->decryptHash($in_hash);

        $date = new \DateTime('now');
        $date->setTimezone(new \DateTimeZone('Africa/Johannesburg'));

        $result = [ 'success' => 1
            ,'course' => $hash
            ,'is_course' => 0
            ,'is_department' => 0
            ,'is_faculty' => 0
            ,'code' => $hash
            ,'hash' => $in_hash
            ,'survey_response' => []
            ,'survey_access_device' => []
            ,'survey_access_type' => []
            ,'survey_activities' => []
            ,'survey_engagement_conditions' => []
            ,'survey_engagement_hours' => []
            ,'survey_countries' => []
            ,'tutor_available' => []
            ,'tutor_access_device' => []
            ,'tutor_access_type' => []
            ,'tutor_activities' => []
            ,'tutor_engagement_conditions' => []
            ,'tutor_hours' => []
            ,'created_at' =>  $date->format('Y-m-d H:i:s')
            ,'updated_at' => ''
            ,'err_msg' => 'The reference was not found, please contact cilt-helpdesk@uct.ac.za.'
        ];

        $var = [];
        $where = '';
        $where_tutor = '';

        if (strtoupper($hash) == "TEST") {
            // everything
            $result['course'] = "ALL Results";
            $where_tutor = 'where `cohort`.EID in (select `tutor`.EID from studentsurvey.results_tutors `tutor`)';
        } else {

            if (preg_match("/^[A-Z]{3}[\d]{4}[A-Z]{1}$/", strtoupper($hash))) {
                // this is a course :)
                $var = [':courseCode' => strtoupper($hash)];
                $where = 'where `cohort`.EID in (select EID from studentsurvey.cohort_class where courseCode = :courseCode)';
                $where_tutor = 'where `cohort`.EID in (select `tutor`.EID from studentsurvey.results_tutors `tutor` where `tutor`.courseCode = :courseCode)';

                $result['course'] = strtoupper($hash);
                $result['code'] = strtoupper($hash);
                $result['is_course'] = 1;

            } elseif (in_array(strtoupper($hash), ["COM","EBE","HUM","LAW","MED","SCI"])) {
                // faculty
                $var = [':faculty' => strtoupper($hash)];
                $where = 'where `cohort`.facultyCode = :faculty';
                $where_tutor = 'where `cohort`.EID in (select `tutor`.EID from studentsurvey.results_tutors `tutor` where `tutor`.faculty = :faculty)';

                $result['is_faculty'] = 1;
                try {
                    $query = "SELECT * FROM timetable.uct_faculty `faculty` where `faculty`.`code` = :faculty";

                    $stmt = $this->dbh->prepare($query);
                    $stmt->execute($var);
                    if ($stmt->rowCount() === 0) {
                        $result = [
                            'success' => 0,
                            'err' => 'faculty'];
                    }

                    $faculty = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    $result['course'] = $faculty[0]['name'];
                    $result['code'] = $faculty[0]['code'];
                } catch (\PDOException $e) {
                    $result = [ 'success' => 0, 'err' => $e->getMessage()];
                }
            } else {
                // so probably a Department
                $data = $this->getHOD($hash);
                if ($data['success']) {
                    $var = [':dept' => "$hash%"];
                    $where = 'where `cohort`.EID in (select EID from studentsurvey.cohort_class where courseCode like :dept)';
                    $where_tutor = 'where `cohort`.EID in (select `tutor`.EID from studentsurvey.results_tutors `tutor` where `tutor`.dept = :dept)';

                    $data = $data['result'][0];
                    $result['course'] = trim($data['name']);
                    $result['code'] = strtoupper($hash);
                    $result['is_department'] = 1;
                } else {
                    $result['success'] = 0;
                    $result['err'] = "Invalid reference.";
                }
            }
        }

        $result['var'] = $var;
        $result['where'] = $where;
        $result['where_tutor'] = $where_tutor;
        $result['encryptHash'] = $this->encryptHash($hash);
        // return $result;

        // updated_at
        try {
            $query = "SELECT max(`results`.updated_at) as d
            FROM studentsurvey.results_valid `results`
                left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID $where;";

            $stmt = $this->dbh->prepare($query);
            $stmt->execute($var);
            if ($stmt->rowCount() === 0) {
                $result['success'] = 0;
                $result['err'] = 'updated_at';
            }

            $result['updated_at'] = $stmt->fetchAll(\PDO::FETCH_ASSOC)[0]['d'];
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        // cohort_response
        $result['cohort_response'] = $this->getSurveyResultsExec($var,
                "SELECT count(*) as cnt, `cohort`.level as lvl
                    FROM studentsurvey.cohort `cohort`
                        $where
                    group by `cohort`.level");

        // survey_response
        $result['survey_response'] = $this->getSurveyResultsExec($var,
                "SELECT count(*) as cnt, `cohort`.level as lvl
                    FROM studentsurvey.results_valid `results`
                        left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                        $where
                    group by `cohort`.level");

        // survey_access_device
        $result['survey_access_device'] = $this->getSurveyResultsExec($var,
                "SELECT
                        sum(`results`.Q3 REGEXP 'Laptop') as Laptop,
                        sum(`results`.Q3 REGEXP 'Desktop computer') as Desktop,
                        sum(`results`.Q3 REGEXP 'Smartphone') as Smartphone,
                        sum(`results`.Q3 REGEXP 'Tablet') as Tablet,
                        sum(`results`.Q3 REGEXP \"I don't have access to any device\") as Nothing,
                        count(*) as cnt,
                        `results`.Q3
                    FROM studentsurvey.results_valid `results`
                        left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    $where
                    group by `results`.Q3
                    order by `results`.Q3");

        //survey_access_type
        $result['survey_access_type'] = $this->getSurveyResultsExec($var,
                "SELECT
                        sum(`results`.Q5 REGEXP 'Mobile data') as Mobile,
                        sum(`results`.Q5 REGEXP 'Wifi') as Wifi,
                        sum(`results`.Q5 REGEXP 'Other') as Other,
                        sum(`results`.Q5 REGEXP 'No access') as Nothing,
                        count(*) as cnt,
                        `results`.Q5
                    FROM studentsurvey.results_valid `results`
                        left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    $where
                    group by `results`.Q5
                    order by `results`.Q5");

        //survey_activities
        $result['survey_activities'] = $this->getSurveyResultsExec($var,
                "SELECT
                        sum(`results`.Q8 REGEXP \"Login to Vula, read announcements, join a chatroom\") as login_vula,
                        sum(`results`.Q8 REGEXP \"Download a reading, notes or presentation from Vula\") as download,
                        sum(`results`.Q8 REGEXP \"Search for and download learning or research materials online or through UCT Library\") as search,
                        sum(`results`.Q8 REGEXP \"Download a lecture video\") as download_500,
                        sum(`results`.Q8 REGEXP \"Play a lecture video online\") as stream,
                        sum(`results`.Q8 REGEXP \"Voice call\") as voice,
                        sum(`results`.Q8 REGEXP \"Live video call or meeting\") as video,
                        sum(`results`.Q8 REGEXP \"I don't know\") as other,
                        sum(`results`.Q8 = \"\") as n,
                        count(*) as cnt,
                        `results`.Q8
                    FROM studentsurvey.results_valid `results`
                        left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    $where
                    group by `results`.Q8
                    order by `results`.Q8");

        //survey_engagement_conditions
        $result['survey_engagement_conditions'] = $this->getSurveyResultsExec($var,
                "SELECT
                        sum(`results`.Q4 REGEXP \"I have a laptop or desktop computer that I can use whenever I need to\") as own_laptop_desktop,
                        sum(`results`.Q4 REGEXP \"I have a laptop or desktop computer but share it with others so it's not always available\") as share_laptop_desktop,
                        sum(`results`.Q4 REGEXP \"I share someone else's laptop or desktop computer, so it's not always available\") as borrow_laptop_desktop,
                        sum(`results`.Q4 REGEXP \"I don't have a laptop or desktop computer that I can use\") as Nothing,
                        count(*) as cnt,
                        `results`.Q4
                    FROM studentsurvey.results_valid `results`
                        left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    $where
                    group by `results`.Q4
                    order by `results`.Q4");

        //survey_engagement_hours
        $result['survey_engagement_hours'] = $this->getSurveyResultsExec($var,
                "SELECT count(`results`.Q7) as cnt, `results`.Q7 as Q
                    FROM studentsurvey.results_valid `results`
                        left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    $where
                    group by `results`.Q7
                    order by `results`.Q7");

        //survey_countries
        $result['survey_countries'] = $this->getSurveyResultsExec($var,
                "SELECT count(*) as `value`, upper(`country`.country) as `id`
                    FROM studentsurvey.cohort `cohort`
                        left join studentsurvey.results_valid `results` on `results`.Q1_EID = `cohort`.EID
                        left join studentsurvey.results_country `country` on `country`.EID = `cohort`.EID
                    $where
                    group by `country`.country
                    having `id` is not null
                    order by `value` desc");

        //tutor_available
        $result['tutor_available'] = $this->getSurveyResultsExec($var,
                "SELECT count(`results`.Q11) as cnt, `results`.Q11 as Q
                    FROM studentsurvey.results_valid `results`
                        left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    $where_tutor
                    group by `results`.Q11
                    order by `results`.Q11");

        //tutor_access_device
        $result['tutor_access_device'] = $this->getSurveyResultsExec($var,
                "SELECT
                        sum(`results`.Q3 REGEXP 'Laptop') as Laptop,
                        sum(`results`.Q3 REGEXP 'Desktop computer') as Desktop,
                        sum(`results`.Q3 REGEXP 'Smartphone') as Smartphone,
                        sum(`results`.Q3 REGEXP 'Tablet') as Tablet,
                        sum(`results`.Q3 REGEXP \"I don't have access to any device\") as Nothing,
                        count(*) as cnt,
                        `results`.Q3
                    FROM studentsurvey.results_valid `results`
                        left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    $where_tutor
                    group by `results`.Q3
                    order by `results`.Q3");

        //tutor_access_type
        $result['tutor_access_type'] = $this->getSurveyResultsExec($var,
                "SELECT
                        sum(`results`.Q5 REGEXP 'Mobile data') as Mobile,
                        sum(`results`.Q5 REGEXP 'Wifi') as Wifi,
                        sum(`results`.Q5 REGEXP 'Other') as Other,
                        sum(`results`.Q5 REGEXP 'No access') as Nothing,
                        count(*) as cnt,
                        `results`.Q5
                    FROM studentsurvey.results_valid `results`
                        left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    $where_tutor
                    group by `results`.Q5
                    order by `results`.Q5");

        //tutor_activities
        $result['tutor_activities'] = $this->getSurveyResultsExec($var,
                "SELECT
                        sum(`results`.Q8 REGEXP \"Login to Vula, read announcements, join a chatroom\") as login_vula,
                        sum(`results`.Q8 REGEXP \"Download a reading, notes or presentation from Vula\") as download,
                        sum(`results`.Q8 REGEXP \"Search for and download learning or research materials online or through UCT Library\") as search,
                        sum(`results`.Q8 REGEXP \"Download a lecture video\") as download_500,
                        sum(`results`.Q8 REGEXP \"Play a lecture video online\") as stream,
                        sum(`results`.Q8 REGEXP \"Voice call\") as voice,
                        sum(`results`.Q8 REGEXP \"Live video call or meeting\") as video,
                        sum(`results`.Q8 REGEXP \"I don't know\") as other,
                        sum(`results`.Q8 = \"\") as n,
                        count(*) as cnt,
                        `results`.Q8
                    FROM studentsurvey.results_valid `results`
                        left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    $where_tutor
                    group by `results`.Q8
                    order by `results`.Q8");

        //tutor_engagement_conditions
        $result['tutor_engagement_conditions'] = $this->getSurveyResultsExec($var,
                "SELECT
                        sum(`results`.Q4 REGEXP \"I have a laptop or desktop computer that I can use whenever I need to\") as own_laptop_desktop,
                        sum(`results`.Q4 REGEXP \"I have a laptop or desktop computer but share it with others so it's not always available\") as share_laptop_desktop,
                        sum(`results`.Q4 REGEXP \"I share someone else's laptop or desktop computer, so it's not always available\") as borrow_laptop_desktop,
                        sum(`results`.Q4 REGEXP \"I don't have a laptop or desktop computer that I can use\") as Nothing,
                        count(*) as cnt,
                        `results`.Q4
                    FROM studentsurvey.results_valid `results`
                        left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    $where_tutor
                    group by `results`.Q4
                    order by `results`.Q4");

        //tutor_hours
        $result['tutor_hours'] = $this->getSurveyResultsExec($var,
                "SELECT count(`results`.Q12) as cnt, `results`.Q12 as Q
                    FROM studentsurvey.results_valid `results`
                        left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    $where_tutor
                    group by `results`.Q12
                    order by `results`.Q12");

        return $result;
    }

    public function getRawSurveyResults($in_hash, $is_tutor) {

        $var = [];
        $where = '';
        $where_tutor = '';
        $tutor_columns = '';
        $hash = $this->decryptHash($in_hash);
        $result = [ 'success' => 1
            ,'result' => null
            ,'code' => $hash
            ,'hash' => $in_hash
        ];

        if (strtoupper($hash) == "TEST") {
            // everything
            $where_tutor = 'where `cohort`.EID in (select `tutor`.EID from studentsurvey.results_tutors `tutor`)';
        } else {

            if (preg_match("/^[A-Z]{3}[\d]{4}[A-Z]{1}$/", strtoupper($hash))) {
                // this is a course :)
                $var = [':courseCode' => strtoupper($hash)];
                $where = 'where `cohort`.EID in (select EID from studentsurvey.cohort_class where courseCode = :courseCode)';
                $where_tutor = 'where `cohort`.EID in (select `tutor`.EID from studentsurvey.results_tutors `tutor` where `tutor`.courseCode = :courseCode)';
            } elseif (in_array(strtoupper($hash), ["COM","EBE","HUM","LAW","MED","SCI","TEST"])) {
                // faculty
                $var = [':faculty' => strtoupper($hash)];
                $where = 'where `cohort`.facultyCode = :faculty';
                $where_tutor = 'where `cohort`.EID in (select `tutor`.EID from studentsurvey.results_tutors `tutor` where `tutor`.faculty = :faculty)';
            } else {
                // so probably a Department
                $data = $this->getHOD($hash);
                if ($data['success']) {
                    $var = [':dept' => "$hash%"];
                    $where = 'where `cohort`.EID in (select EID from studentsurvey.cohort_class where courseCode like :dept)';
                    $where_tutor = 'where `cohort`.EID in (select `tutor`.EID from studentsurvey.results_tutors `tutor` where `tutor`.dept = :dept)';
                } else {
                    $result['success'] = 0;
                    $result['err'] = "Invalid reference.";
                }
            }
        }

        if ($is_tutor) {
            $tutor_columns = "ifnull(`results`.Q9,'') as Q9,
                            ifnull(`results`.Q10,'') as Q10,
                            ifnull(`results`.Q11,'') as Q11,
                            ifnull(`results`.Q12,'') as Q12,";
            $where = $where_tutor;
        }

        $week_cols = [];
        $week_join = [];
        try {
            $week_stmt = $this->dbh->prepare("SELECT `term`, `week`, `start_date`, `end_date`,
                                    ADDDATE(`start_date`, INTERVAL 3 DAY) as `wed`,
                                    ADDDATE(`start_date`, INTERVAL 3 DAY) <= now() as `week_active`
                                    FROM vula_archive.Weeks
                                    WHERE `week` >= 2 and `week` <= 50 and `start_date` >= '2020-04-26' and `start_date` <= NOW()");
            $week_stmt->execute();
            foreach ($week_stmt->fetchAll(\PDO::FETCH_ASSOC) as $r => $row) {
                $w_name = ($r+1);
                $w_active = $row['week_active'];
                array_push($week_cols, "ifnull(`w".$r."`.`DEVICES`,'') as Week_". $w_name ."_Devices");
                array_push($week_cols, "ifnull(`w".$r."`.`STATUS`,'". ($w_active ? "RED" : "") ."')  as Week_". $w_name ."_Status");
                array_push($week_cols, "ifnull(`w".$r."`.`UPDATED`,'') as Week_". $w_name ."_Updated");
                array_push($week_join, "left join vula_archive.STUDENT_WEEK_CLASSIFICATION `w".$r."` on `w".$r."`.EID = `cohort`.EID and `w".$r."`.`TERM` = ". $row['term'] ." and `w".$r."`.`WEEK` = ". $row['week']);
            }
        } catch (\PDOException $e) {
            return [ 'success' => 0, 'err' => $e->getMessage()];
        }

        $select_week = count($week_cols) > 0 ? ','. implode(',', $week_cols) : '';
        $join_week = count($week_join) > 0 ? implode(' ', $week_join) : '';

        $result['result'] = $this->getSurveyResultsExec($var,
                "SELECT
                        `cohort`.EID as StudentNumber,
                        `cohort`.firstName as 'Name',
                        `cohort`.lastName as Surname,
                        `cohort`.level, `cohort`.programCode, `cohort`.facultyCode, `cohort`.careerCode,
                        ifnull(studentsurvey.getCoursesFromEID(`cohort`.EID),'') as courseCodes,
                        ifnull(`results`.recordedDate,'') as recordedDate,
                        ifnull(`results`.Q2,'') as Q2 ,
                        ifnull(`results`.Q3,'') as Q3,
                        ifnull(`results`.Q4,'') as Q4,
                        ifnull(`results`.Q5,'') as Q5,
                        ifnull(`results`.Q6,'') as Q6,
                        ifnull(`results`.Q7,'') as Q7,
                        ifnull(`results`.Q8,'') as Q8,
                        $tutor_columns
                        ifnull(`country`.country,'') as Country,
                        ifnull(if(`orientation`.answer = 1, \"ok\", if(`orientation`.answer = 0, \"unsure\",\"\")),'') as OrientationResponse,
                        ifnull(`orientation`.updated,'') as OrientationUpdated,
                        ifnull(`week`.devices,'') as OrientationWeekDevices,
                        ifnull(`week`.`status`,'RED') as OrientationWeekStatus,
                        ifnull(`week`.updated,'') as OrientationWeekUpdated
                        $select_week
                    FROM studentsurvey.cohort `cohort`
                        left join studentsurvey.results_valid `results` on `results`.Q1_EID = `cohort`.EID
                        left join studentsurvey.results_country `country` on `country`.EID = `cohort`.EID
                        left join studentsurvey.view_orientation `orientation` on `orientation`.EID = `cohort`.EID
                        left join studentsurvey.results_orientation_week `week` on `week`.EID = `cohort`.EID
                        $join_week
                    $where
                    order by `cohort`.EID;");
        return $result;
    }

    public function getSurveyForEmail($in_hash) {

        $hash = $this->decryptHash($in_hash);
        $result = [
            'success' => 1
            ,'updated_at' => ""
            ,'title' => ""
            ,'name' => ""
            ,'email' => ""
            ,'code' => $hash
            ,'hash' => $in_hash
            ,'link' => 'https://srvubuclt001.uct.ac.za/optout/survey/'. $in_hash
            ,'is_course' => 0
            ,'is_department' => 0
            ,'is_faculty' => 0
        ];
        $var = [];
        $where = '';

        if (strtoupper($hash) == "TEST") {
            // everything
        } else {

            if (preg_match("/^[A-Z]{3}[\d]{4}[A-Z]{1}$/", strtoupper($hash))) {
                // this is a course :)
                $var = [':courseCode' => strtoupper($hash)];
                $where = 'where `cohort`.EID in (select EID from studentsurvey.cohort_class where courseCode = :courseCode)';

                try {
                    $query = "select A.course_code, A.title, A.dept,
                    ifnull(C.convenor_name, A.convenor_name) as convenor_name,
                    ifnull(C.convenor_eid, A.convenor_eid) as convenor_eid,
                    ifnull(C.convenor_email, (select E.email from timetable.view_sakai_users E where C.convenor_eid = E.eid or (C.convenor_eid is null and A.convenor_eid = E.eid))) as email
                        from timetable.ps_courses A
                        left join timetable.course_updates C on A.course_code = C.course_code and C.year = 2020 and C.workflow_id = 4
                    where A.term = 2020 and A.start_date < '2020-06-01' and A.course_code in (select distinct courseCode from studentsurvey.cohort_class)
                    and A.course_code = :courseCode";

                    $stmt = $this->dbh->prepare($query);
                    $stmt->execute($var);
                    if ($stmt->rowCount() === 0) {
                        $result['success'] = 0;
                        $result['err'] = "Invalid reference.";
                    }

                    $course = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    $result['title'] = $course[0]['course_code'];
                    $result['name'] = $course[0]['convenor_name'];
                    $result['email'] = $course[0]['email'];
                    $result['eid'] = $course[0]['convenor_eid'];
                    $result['is_course'] = 1;

                    // Get Firstname and lastname from EID
                    if ($course[0]['convenor_eid'] != '') {
                        $user = (new User($course[0]['convenor_eid']))->getDetails();
                        $result['name'] = $user['first_name'] .' '. $user['last_name'];
                        $result['email'] = $user['email'];
                    }

                    if ($result['email'] == '') {
                        // empty course convenor
                        $data = $this->getHOD($course[0]['dept']);
                        if ($data['success']) {
                            $data = $data['result'][0];
                            $result['name'] = trim($data['user']);
                            $result['email'] = trim($data['email']);
                            // $result['is_department'] = 1;
                        } else {
                            $result = $data;
                        }
                    }
                } catch (\PDOException $e) {
                    $result = [ 'success' => 0, 'err' => $e->getMessage()];
                }

            } elseif (in_array(strtoupper($hash), ["COM","EBE","HUM","LAW","MED","SCI"])) {
                // faculty
                $var = [':faculty' => strtoupper($hash)];
                $where = 'where `cohort`.facultyCode = :faculty';

                $result['is_faculty'] = 1;
                try {
                    $query = "SELECT * FROM timetable.uct_faculty `faculty` where `faculty`.`code` = :faculty";

                    $stmt = $this->dbh->prepare($query);
                    $stmt->execute($var);
                    if ($stmt->rowCount() === 0) {
                        $result = [
                            'success' => 0,
                            'err' => 'faculty'];
                    }

                    $faculty = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    $result['title'] = $faculty[0]['name'];
                } catch (\PDOException $e) {
                    $result = [ 'success' => 0, 'err' => $e->getMessage()];
                }
            } else {
                // so probably a Department
                $var = [':dept' => "$hash%"];
                $where = 'where `cohort`.EID in (select EID from studentsurvey.cohort_class where courseCode like :dept)';

                $data = $this->getHOD($hash);
                if ($data['success']) {
                    $data = $data['result'][0];
                    $result['title'] = trim($data['name']);
                    $result['name'] = trim($data['user']);
                    $result['email'] = trim($data['email']);
                    $result['is_department'] = 1;
                } else {
                    $result['success'] = 0;
                    $result['err'] = "Invalid reference.";
                }
            }
        }

        $state = 0;
        try {
            $query = "SELECT `state` FROM timetable.results_notification_emails where mail_to = :mail and code = :code limit 1;";

            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':code' => strtoupper($hash), ':mail' => $result['email']]);
            if ($stmt->rowCount() === 0) {
                $state = 0;
            } else {
                $state = $stmt->fetchAll(\PDO::FETCH_ASSOC)[0]['state'];
            }
        } catch (\Exception $e) {
            $state = 0;
        }
        $result['state'] = $state;


        // updated_at
        try {
            $query = "SELECT max(`results`.updated_at) as d
            FROM studentsurvey.results_valid `results`
                left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID $where;";

            $stmt = $this->dbh->prepare($query);
            $stmt->execute($var);
            if ($stmt->rowCount() === 0) {
                $result['success'] = 0;
                $result['err'] = 'updated_at';
            }

            $result['updated_at'] = $stmt->fetchAll(\PDO::FETCH_ASSOC)[0]['d'];
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $result;
    }

    public function getHOD($dept) {
        $result = [ 'success' => 1, 'err' => '', 'result' => []];

        try {
            $var = [':dept' => strtoupper($dept)];
            // $where = 'where `cohort`.facultyCode = :faculty';

            $query = "SELECT `dept`,`name`, concat(firstname,' ',lastname) as `user`, email FROM timetable.uct_dept where dept = :dept";

            $stmt = $this->dbh->prepare($query);
            $stmt->execute($var);
            if ($stmt->rowCount() === 0) {
                $result['success'] = 0;
                $result['err'] = "Invalid reference.";
            }

            $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $result;
    }

    public function encryptHash($hash) {

        // Store the cipher method
        $ciphering = "AES-128-CTR";

        // Use OpenSSl Encryption method
        $iv_length = openssl_cipher_iv_length($ciphering);
        $options = 0;

        // Non-NULL Initialization Vector for encryption
        $encryption_iv = '7767875091113121';

        // Store the encryption key
        $encryption_key = "AbstractOrganisationalEntity";

        // Use openssl_encrypt() function to encrypt the data
        return openssl_encrypt($hash, $ciphering, $encryption_key, $options, $encryption_iv);
    }

    public function decryptHash($val) {

        if (strtoupper($val) == "TEST") {
            return $val;
        }

        // Store the cipher method
        $ciphering = "AES-128-CTR";

        // Use OpenSSl Encryption method
        $iv_length = openssl_cipher_iv_length($ciphering);
        $options = 0;

        // Non-NULL Initialization Vector for decryption
        $decryption_iv = '7767875091113121';

        // Store the decryption key
        $decryption_key = "AbstractOrganisationalEntity";

        // Use openssl_decrypt() function to decrypt the data
        return openssl_decrypt ($val, $ciphering, $decryption_key, $options, $decryption_iv);
    }

    public function generateResultEmails() {
        $done = [ 'count' => 0, 'pass' => 0, 'mail' => 0];

        $faculties = array("TEST","COM","EBE","HUM","LAW","MED","SCI");
        foreach ($faculties as &$hash) {

            $data = $this->getSurveyForEmail($this->encryptHash($hash));

            if ($data['success']) {
                if ($data['state'] == 0) {
                    $done['mail'] += $this->addResultEmails($data['hash'], $data['name'], $data['email'], '', $hash, "faculty", $data['state']) ? 1 : 0;
                } else {
                    $done['pass'] ++;
                }
            }
        }

        try {
            $query = "select substr(courseCode,1,3) as code from studentsurvey.cohort_class group by substr(courseCode,1,3);";

            $stmt = $this->dbh->prepare($query);
            $stmt->execute();
            while ($line = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $data = $this->getSurveyForEmail($this->encryptHash($line['code']));

                if ($data['success']) {
                    if ($data['state'] == 0) {
                        $done['mail'] += $this->addResultEmails($data['hash'], $data['name'], $data['email'], '', $line['code'], "dept", $data['state']) ? 1 : 0;
                    } else {
                        $done['pass'] ++;
                    }
                }
            }
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        try {
            $query = "select A.course_code
                        from timetable.ps_courses A
                    where A.term = 2020 and A.start_date < '2020-06-01'
                    and A.course_code in (SELECT `cls`.courseCode FROM studentsurvey.cohort `cohort`
                                            left join studentsurvey.cohort_class `cls` on `cls`.EID = `cohort`.EID
                                            where `cohort`.careerCode not in ('PDOC','NDGP')
                                                and NOT(`cls`.courseCode regexp '(.*)[S]$') and NOT(`cls`.courseCode regexp '^[A-Z]{3}9'))";
                    //and A.course_code not in (select `code` from timetable.results_notification_emails);"; // only if they don't already exist

            $stmt = $this->dbh->prepare($query);
            $stmt->execute();
            while ($line = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $data = $this->getSurveyForEmail($this->encryptHash($line['course_code']));

                if ($data['success']) {
                    if ($data['state'] == 0) {
                        $done['mail'] += $this->addResultEmails($data['hash'], $data['name'], $data['email'], '', $line['course_code'], "course", $data['state']) ? 1 : 0;
                    } else {
                        $done['pass'] ++;
                    }
                }
            }
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $done;
    }

    public function addResultEmails($hash, $name, $mail_to, $mail_cc, $code, $type, $state) {

        $insertQry = "replace into timetable.results_notification_emails (hash, name, mail_to, mail_cc, state, code, type)
                        VALUES (:hash, :name, :mail_to, :mail_cc, :state, :code, :type)";

        if (trim($mail_to) === '') {
            $name = 'Stephen Marquard';
            $mail_to = 'stephen.marquard@uct.ac.za';
        }

        try {
            $insertStmt = $this->dbh->prepare($insertQry);
            $bind = [
                ':hash' => $hash,
                ':code' => $code,
                ':type' => $type,
                ':name' => $name,
                ':mail_to' => $mail_to,
                ':mail_cc' => $mail_cc,
                ':state' => $state
            ];
            $insertStmt->execute($bind);
            if ($insertStmt->rowCount() === 0) {
                return FALSE;
            }
        } catch (\PDOException $e) {
            return 'ERR:'. $e->getMessage();
        }

        return TRUE;
    }

    // Function to check string starting with given substring
    function startsWith ($string, $startString) {
        $len = strlen($startString);
        return (substr($string, 0, $len) === $startString);
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

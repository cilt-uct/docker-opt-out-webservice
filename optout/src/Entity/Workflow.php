<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Course;
use App\Entity\Department;
use App\Service\Utilities;

class Workflow
{
    private $dbh = null;

    private $oid;
    private $year;
    private $semester;
    private $status;
    private $active;
    /**
     * @Assert\DateTime()
     */
    private $date_start;
    /**
     * @Assert\DateTime()
     */
    private $date_dept;
    /**
     * @Assert\DateTime()
     */
    private $date_course;
    /**
     * @Assert\DateTime()
     */
    private $date_schedule;


    public function __construct() {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        try {
            $query = "select * from uct_workflow where active = 1 limit 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                $this->oid = $result[0]['id'];
                $this->year = $result[0]['year'];
                $this->semester = $result[0]['semester'];
                $this->status = $result[0]['status'];
                $this->date_start = new \DateTime($result[0]['date_start']);
                $this->date_dept = new \DateTime($result[0]['date_dept']);
                $this->date_course = new \DateTime($result[0]['date_course']);
                $this->date_schedule = new \DateTime($result[0]['date_schedule']);
                $this->active = ($result[0]['active'] ? true : false);
            } else {
                $this->status = $stmt->rowCount();
            }
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Get active workflow (only one running at any one time)
     */
    public function getWorkflow() {
        return [
            'oid' => $this->oid,
            'year' => $this->year,
            'semester' => $this->semester,
            'status' => $this->status,
            'date_start' => $this->date_start,
            'date_dept' => $this->date_dept,
            'date_course' => $this->date_course,
            'date_schedule' => $this->date_schedule,
            'active' => $this->active
        ];
    }

    /**
     * Run the workflow
     */
    public function run() {

        $now = new \DateTime();
        $result = [ 'success' => 1 ];
        $result['result'] = 'running: '. $this->status;

        //ENUM('init', 'start', 'run', 'dept', 'dept_mail', 'course', 'course_mail', 'done')
        switch($this->status) {
            case 'init':

                if ($this->startWorkflow()) {
                    // > state: start
                    $this->setState('start');
                    $result['result'] = $result['result'] ." - switch to start";
                } else {
                    $result = [ 'success' => 0, 'err' => 'Error starting workflow'];
                }
                // So initialization of this workflow
                break;
            case 'start':
                // (wait for start date)
                if ($now->diff($this->date_start)->format('%R') == '-') {
                    // > status : run
                    $this->setState('run');
                    $result['result'] = $result['result'] ." - switch to run";
                } else {
                    $result['result'] = $result['result'] ." - waiting for ". $this->date_start->format("Y-m-d H:i:s");
                }
                break;
            case 'run':
                // (wait for department date)
                if ($now->diff($this->date_dept)->format('%R') == '-') {

                    // Create email entries for each departments head, so mail can be sent to each
                    $action = $this->createDepartmentMails();
                    if ($action === 1) {

                        // > state: dept
                        $this->setState('dept');
                        $result['result'] = $result['result'] ." - switch to dept";
                    } else {
                        $result = [ 'success' => 0, 'err' => $action];
                    }
                } else {
                    $result['result'] = $result['result'] ." - waiting for ". $this->date_dept->format("Y-m-d H:i:s");
                }
                break;
            case 'dept':
                // (wait for course date)
                if ($now->diff($this->date_course)->format('%R') == '-') {

                    // Create email entries for each COURSE, so mail can be sent to each
                    if ($this->createCourseMails()) {

                        // > state: course
                        $this->setState('course');
                        $result['result'] = $result['result'] ." - switch to course";
                    } else {
                        $result = [ 'success' => 0, 'err' => 'Error creating course mails'];
                    }
                } else {
                    $result['result'] = $result['result'] ." - waiting for ". $this->date_course->format("Y-m-d H:i:s");
                }
                break;
            case 'course':
                // (wait for schedule date)
                if ($now->diff($this->date_schedule)->format('%R') == '-') {

                    // do scheduling
                    // TODO -
                    //    - srvubuopc001:/usr/local/cetscripts/peoplesoft/automaticScheduling.pl
                    //    - srvubuclt001:/usr/local/sakaiscripts/jira/optout_dept.pl

                    // > state: done
                    $this->setState('done');
                    $result['result'] = $result['result'] ." - switch to done";
                } else {
                    $result['result'] = $result['result'] ." - waiting for ". $this->date_schedule->format("Y-m-d H:i:s");
                }
                break;
            case 'done':
                // All done
                // create new workflow that will run in the future
                // set this one inactive
                /*
                try {
                    $query = "UPDATE uct_workflow SET active = 0 WHERE active = 1";
                    $stmt = $this->dbh->prepare($query);
                    $stmt->execute();
                    $this->active = false;

                    // TODO: create new workflow
                    /*
                    $query = "INSERT INTO uct_workflow (`year`,`date_start`,`date_dept`,`date_course`,`date_schedule`)
                    VALUES (2019,<{date_start: }>,<{date_dept: }>,<{date_course: }>,<{date_schedule: }>)";
                    $stmt = $this->dbh->prepare($query);
                    $stmt->execute();
                    $this->active = false;
                    *

                    $result['result'] = $result['result'] ." - New Workflow";
                } catch (\PDOException $e) {
                    $result = [ 'success' => 0, 'err' => $e->getMessage()];
                }
                */
                break;
            default:
                $result = [ 'success' => 0, 'err' => 'Error running workflow'];
                break;
        }

        return $result;
    }

    /**
     * Start the process
     */
    private function startWorkflow(){
        try {
            // Populate the dept_opt_out table
            $query = "INSERT INTO `timetable`.`dept_optout` (`dept`, `year`, `workflow_id`)
                    SELECT `dept`, :year, :workflow_id FROM `timetable`.`uct_dept` where `exists` = 1 and `active` = 1
                    ON DUPLICATE KEY UPDATE `dept`=VALUES(`dept`), `year`=VALUES(`year`), `workflow_id`=VALUES(`workflow_id`);";

            $stmt = $this->dbh->prepare($query);

            if (!$stmt->execute([':year' => $this->year, ':workflow_id' => $this->oid])) {
                return $this->dbh->errorInfo();
            } else {
                $utils = new Utilities();
                $updateResults = $utils->refreshCourses();
            }
        } catch (\PDOException $e) {
            return $e->getMessage();
        }

        return 1;
    }

    private function createDepartmentMails(){

        try {
            // get list of departments
            $query = "SELECT * FROM uct_dept where `exists` = 1 and `use_dept` = 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute();

            $ar = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $dept = new Department($row['dept'], null, $this->year, true);

                array_push($ar, '('. $this->oid .',"'. $row['dept'] .'",'. $this->year .',"'. $row['email'] .'","'.
                        $row['alt_email'] .'","'. $dept->getHash() .'","'.
                        ( strlen($row['firstname']."".$row['lastname']) < 2 ? "Colleague" : $row['firstname'] ." ". $row['lastname']) .'")');
            }

            array_push($ar, '('. $this->oid .',"ZZZ",'. $this->year .',"stephen.marquard@uct.ac.za","sam.leepan@uct.ac.za;corne.oosthuizen@uct.ac.za","zzzd'.$this->year.'","Stephen Marquard")');
            $insertQry = "INSERT INTO `uct_workflow_email` (`workflow_id`, `dept`, `term`, `mail_to`, `mail_cc`, `hash`, `name`) VALUES ". implode(',', $ar);

            $mailStmt = $this->dbh->prepare($insertQry);
            if (!$mailStmt->execute($ar)) {
                return $this->dbh->errorInfo();
            }
        } catch (\PDOException $e) {
            return $e->getMessage();
        }

        return 1;
    }

    private function createCourseMails(){
        try {
            // get list of courses - only eligible courses
            // $query = "SELECT distinct(`course`.course_code) as course_code, `course`.dept as dept
            //     FROM timetable.course_optout `course`
            //     left join timetable.ps_courses `ps` on `ps`.course_code =  `course`.course_code and `ps`.term = `course`.year
            //     left join timetable.sn_timetable_versioned `sn` on `sn`.course_code = `course`.course_code and `sn`.term = `course`.year
            //     left join timetable.dept_optout `deptout` on `course`.`dept` = `deptout`.`dept`
            //     left join timetable.uct_dept `dept` on `course`.`dept` = `dept`.`dept`
            //     left join timetable.opencast_venues on `sn`.archibus_id = opencast_venues.archibus_id
            //     where `dept`.use_dept = 1 and `deptout`.is_optOut = 0 and `ps`.active = 1
            //         and `ps`.acad_career = 'UGRD' and opencast_venues.campus_code in (". Course::ELIGIBLE .")";
            $query = "SELECT DISTINCT
                            `versioned`.`course_code` AS `course_code`,
                            `ps`.`dept`
                        FROM
                            `timetable`.`sn_timetable_versioned` `versioned`
                            JOIN `timetable`.`opencast_venues` `venues` ON `versioned`.`archibus_id` = `venues`.`archibus_id`
                            JOIN `timetable`.`ps_courses` `ps` ON
                                (`versioned`.`course_code` = `ps`.`course_code` AND `versioned`.`term` = `ps`.`term`)
                            LEFT JOIN `timetable`.`dept_optout` `deptout` ON
                                (`deptout`.`dept` = `ps`.`dept` AND `deptout`.`year` = `versioned`.`term`)
                            LEFT JOIN `timetable`.`uct_dept` `dept` ON `ps`.`dept` = `dept`.`dept`
                        WHERE
                            ((`versioned`.`term` = :term)
                                AND (`dept`.use_dept = 1) and (`deptout`.is_optOut = 0) and (`dept`.exists = 1)
                                AND (`versioned`.`instruction_type` IN ('Lecture' , 'Module'))
                                AND `versioned`.`tt_version` IN (SELECT
                                    MAX(`timetable`.`timetable_versions`.`version`)
                                FROM
                                    `timetable`.`timetable_versions`)
                                AND (`ps`.`course_code` REGEXP :codes)
                                AND (`venues`.`campus_code` IN ('UPPER' , 'MIDDLE'))
                                AND (`ps`.`acad_career` = 'UGRD'))
                        ORDER BY `versioned`.`course_code`";

            $stmt = $this->dbh->prepare($query);

            if ($this->semester == 's1') {
                $stmt->execute([':codes' => Course::SEM1, ':term' => $this->year]);
            } else {
                $stmt->execute([':codes' => Course::SEM2, ':term' => $this->year]);
            }

            $ar = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $course = new Course($row['course_code'], null, $this->year, true);
                $details = $course->getDetails();
                $has_oc_series = $course->checkIsTimetabledInOC();

                if ($has_oc_series == false) {
                    $to = [ 'mail' => $details['convenor']['email'],
                            'name' => $details['convenor']['name']];

                    if ($to['mail'] == null) {
                        $dept = new Department($row['dept'], null, $this->year, true, true);
                        $dept_details = $dept->getDetails();

                        $to = [ 'mail' => ($details['convenor']['email'] == null ? $dept_details['mail'] : $details['convenor']['email']),
                                'name' => ($details['convenor']['email'] == null ? $dept_details['hod'] : $details['convenor']['name'])];

                        if (strlen($to['name']) < 2) {
                            $to['name'] = "Colleague";
                        }
                    }

                    array_push($ar, '('. $this->oid .',"'. $row['dept'] .'","'. $row['course_code'] .'","'.
                        $to['mail'] .'","'.
                        $course->getHash() .'","'.
                        $to['name'] .'",'.
                        $this->year .')');
               }
            }

            // test
            array_push($ar, '('. $this->oid .',"ZZZ","ZZZ1000S","stephen.marquard@uct.ac.za","zzzc'.$this->year.'","Stephen Marquard",'. $this->year .')');
            $insertQry = "INSERT INTO `uct_workflow_email` (`workflow_id`, `dept`, `course`, `mail_to`, `hash`, `name`, `term`) VALUES ". implode(',', $ar);
            //return $insertQry;

            $mailStmt = $this->dbh->prepare($insertQry);
            if (!$mailStmt->execute($ar)) {
                return $this->dbh->errorInfo();
            }
        } catch (\PDOException $e) {
            return $e->getMessage();
        }

        return 1;
    }

    private function setState($status) {
        try {
            $query = "UPDATE uct_workflow SET status = :status WHERE active = 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':status' => $status]);
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
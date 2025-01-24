<?php
// src/Controller/UIController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Psr\Log\LoggerInterface;

use App\Entity\Course;
use App\Entity\Department;
use App\Entity\OpencastRetentionBatch;
use App\Entity\Workflow;
use App\Entity\User;

use App\Service\D2LWebService;
use App\Service\LDAPService;
use App\Service\OCRestService;
use App\Service\SakaiWebService;
use App\Service\Utilities;

class UIController extends Controller
{
    private $d2l;
    private $oc;
    private $ldapService;
    private $logger;

    public function __construct(D2LWebService $d2l, OCRestService $oc, LDAPService $ldapService, LoggerInterface $logger)
    {
        $this->d2l = $d2l;
        $this->oc = $oc;
        $this->ldapService = $ldapService;
        $this->logger = $logger;
    }

    /**
     * test page
     *
     * @Route("/test/{hash}", name="test")
     */
    public function test($hash, Request $request) {


        // return new Response((new Workflow)->createCourseMails(), 201);

	    $authenticated = ['a' => false, 'z' => '0'];

        $now = new \DateTime();
        $utils = new Utilities();
        $data = $utils->getMail($hash);

        switch ($request->getMethod()) {
            case 'POST':
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');

                try {
                    if ($this->ldapService->authenticate($user, $password)) {
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $user);
                        $authenticated['a'] = true;
                        $z = $utils->getAuthorizedUsers($user);
                        if ($z) {
                            if ($z['success']) {
                                $authenticated['z'] = $z['result'][0];
                                $authenticated['z']['success'] = 1;
                            }
                        }
                    } else {
                        $authenticated['z'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['z'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['z'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;

                $z = $utils->getAuthorizedUsers($session->get('username'));
                if ($z) {
                    if ($z['success']) {
                        $authenticated['z'] = $z['result'][0];
                        $authenticated['z']['success'] = 1;
                    }
                }
            break;
        }
        // return new Response(json_encode($data), 201);

        if (!$data['success']) {
            return $this->render('error.html.twig', $data);
        } else {
            $data = $data['result'][0];
            // $hash = $data['hash'];
            $data['out_link'] = 'https://srvubuclt001.uct.ac.za/optout/out/'. $data['hash'];
            $data['authenticated'] = $authenticated;
        }

        if ($data['course'] === null ) {
            $dept = new Department($data['dept'], $data['hash'], $data['year'], false, false, true);
            $data['details'] = $dept->getDetails();
            $data['readonly'] = ($now->diff(new \DateTime($data['date_course']))->format('%R') == '-');
            $data['readonly_s1'] = ($now->diff(new \DateTime($data['date_course']))->format('%R') == '-');
            $data['readonly_s2'] = ($now->diff(new \DateTime($data['date_course']))->format('%R') == '-');

            // return new Response(json_encode($data), 201);

            if (isset($data['details']['courses'])) {
                if (count($data['details']['courses']) == 0) {
                    return $this->viewOptOut($data['hash'], $request);
                } else {
                    $semester_vals = array_column($data['details']['courses'], 'semester'); // take all 'semester' values
                    $data['counts'] = array_count_values($semester_vals);
                }
            } else {
                return $this->viewOptOut($data['hash'], $request);
            }

            if (!isset($data['counts']['s1'])) { $data['counts']['s1'] = -1; }
            if (!isset($data['counts']['s2'])) { $data['counts']['s2'] = -1; }

            return $this->render('department.html.twig', $data);
        } else {
            // Course View

            $course = new Course($data['course'], $data['hash'], $data['year'], false, true); // last could be set to true

            $data['details'] = $course->getDetails();
            $data['readonly'] = ($now->diff(new \DateTime($data['date_schedule']))->format('%R') == '-');
            $data['hasVulaSite'] = $data['details']['hasVulaSite'];
            $data['hasOCSeries'] = $data['details']['hasOCSeries'];
            $data['hasAmathubaSite'] = $data['details']['hasAmathubaSite'];

            $data['isTimetabled'] = $data['details']['hasOCSeries'] ? $course->checkIsTimetabledInOC() : false;
            $data['email_case'] = $data['case'];
            $data['email_type'] = $data['type'];

            // retrieve timetable information
            $json = file_get_contents('https://srvubuclt001.uct.ac.za/timetable/?historic=1&course='. $data['course'] .','. $data['year']);
            $data['timetable'] = json_decode($json, TRUE);
            if (!isset($data['timetable']['LEC'])) {
                $data['timetable']['LEC'] = [];
            }

            // return new Response(json_encode($data), 201);
            return $this->render('course.html.twig', $data);
        }
    }

    /**
     * View the page according to the hash it receives
     *
     * @Route("/view/{hash}")
     */
    public function viewFromHash($hash, Request $request) {
        $authenticated = ['a' => false, 'z' => '0'];

        $now = new \DateTime();
        $utils = new Utilities();
        $data = $utils->getMail($hash);

        switch ($request->getMethod()) {
            case 'POST':
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');

                try {
                    if ($this->ldapService->authenticate($user, $password)) {
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $user);
                        $authenticated['a'] = true;
                        $z = $utils->getAuthorizedUsers($user);
                        if ($z) {
                            if ($z['success']) {
                                $authenticated['z'] = $z['result'][0];
                                $authenticated['z']['success'] = 1;
                            }
                        }
                    } else {
                        $authenticated['z'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['z'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['z'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;

                $z = $utils->getAuthorizedUsers($session->get('username'));
                if ($z) {
                    if ($z['success']) {
                        $authenticated['z'] = $z['result'][0];
                        $authenticated['z']['success'] = 1;
                    }
                }
            break;
        }
        // return new Response(json_encode($data), 201);

        if (!$data['success']) {
            return $this->render('error.html.twig', $data);
        } else {
            $data = $data['result'][0];
            // $hash = $data['hash'];
            $data['out_link'] = 'https://srvubuclt001.uct.ac.za/optout/out/'. $data['hash'];
            $data['authenticated'] = $authenticated;
        }

        if ($data['course'] === null ) {
            $dept = new Department($data['dept'], $data['hash'], $data['year'], false, false, true);
            $data['details'] = $dept->getDetails();
            $data['readonly'] = ($now->diff(new \DateTime($data['date_course']))->format('%R') == '-');
            $data['readonly_s1'] = ($now->diff(new \DateTime($data['date_course']))->format('%R') == '-');
            $data['readonly_s2'] = ($now->diff(new \DateTime($data['date_course']))->format('%R') == '-');

            // return new Response(json_encode($data), 201);

            if (isset($data['details']['courses'])) {
                if (count($data['details']['courses']) == 0) {
                    return $this->viewOptOut($data['hash'], $request);
                } else {
                    $semester_vals = array_column($data['details']['courses'], 'semester'); // take all 'semester' values
                    $data['counts'] = array_count_values($semester_vals);
                }
            } else {
                return $this->viewOptOut($data['hash'], $request);
            }

            if (!isset($data['counts']['s1'])) { $data['counts']['s1'] = -1; }
            if (!isset($data['counts']['s2'])) { $data['counts']['s2'] = -1; }

            return $this->render('department.html.twig', $data);
        } else {
            // Course View
            $vula = new SakaiWebService();
            $ocService = new OCRestService();

            // $course = new Course($data['course'], $data['hash'], $data['year'], false, false); // last could be set to true
            $course = new Course('ACC1006F', 'be9abc', '2020', false, false);


            $data['details'] = $course->getDetails();
            $data['readonly'] = ($now->diff(new \DateTime($data['date_schedule']))->format('%R') == '-');
            $data['hasVulaSite'] = $vula->hasProviderId($data['course'], $data['year']);
            $data['hasOCSeries'] = $ocService->hasOCSeries($data['course'], $data['year']);
            $data['hasAmathubaSite'] = $this->d2l->hasSite($data['course'], $data['year']);

            $data['isTimetabled'] = $data['hasOCSeries'] ? $course->checkIsTimetabledInOC() : false;
            $data['email_case'] = $data['case'];
            $data['email_type'] = $data['type'];

            // retrieve timetable information
            $json = file_get_contents('https://srvubuclt001.uct.ac.za/timetable/?historic=1&course='. $data['course'] .','. $data['year']);
            $data['timetable'] = json_decode($json, TRUE);
            if (!isset($data['timetable']['LEC'])) {
                $data['timetable']['LEC'] = [];
            }

            // return new Response(json_encode($data), 201);
            return $this->render('course.html.twig', $data);
        }
    }


    /**
     * @Route("/out/{hash}")
     */
    public function viewOptOut($hash, Request $request) {
        $now = new \DateTime();
        $utils = new Utilities();
        $data = $utils->getMail($hash);
        $authenticated = ['a' => false, 'z' => '0'];
        $confirmed = false;

        // get department
        try {
            $dept = new Department($data['result'][0]['dept'], $data['result'][0]['hash'], $data['result'][0]['year']);
            $confirmed = $dept->isOptOut;
        } catch (\Exception $e) {
            $hash = null;
        }

        switch ($request->getMethod()) {
            case 'POST':
                $type = $request->request->get('type');

                switch($type) {
                    case 'login':
                        $user = $request->request->get('eid');
                        $password = $request->request->get('pw');

                        try {
                            if ($this->ldapService->authenticate($user, $password)) {
                                $session = $request->hasSession() ? $request->getSession() : new Session();
                                $session->set('username', $user);
                                $authenticated['a'] = true;
                                $z = $utils->getAuthorizedUsers($user);
                                if ($z) {
                                    if ($z['success']) {
                                        $authenticated['z'] = $z['result'][0];
                                        $authenticated['z']['success'] = 1;
                                    }
                                }
                            } else {
                                $authenticated['z'] = 'Invalid username/password combination';
                            }
                        } catch (\Exception $e) {
                            switch ($e->getMessage()) {
                                case 'no such user':
                                    $authenticated['z'] = 'No such user';
                                break;
                                case 'invalid id':
                                    $authenticated['z'] = 'Please use your official UCT staff number';
                                break;
                            }
                        }
                    break;
                    case 'ask':
                        if ($dept) {

                            $session = $request->hasSession() ? $request->getSession() : new Session();
                            $authenticated['a'] = $session->get('username') ? true : false;
                            $workflow = (new Workflow)->getWorkflow();

                            $updated = $dept->updateOptoutStatus($session->get('username'), ['status' => $request->request->get('optout_confirm') ], $workflow['oid']);
                            $confirmed = $updated['success'];
                        }
                    break;
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;
            break;
        }


        if (!$data['success'] || $hash == null) {
            return $this->render('error.html.twig', $data);
        } else {
            $data = $data['result'][0];
            // $hash = $data['hash'];
            $data['out_link'] = 'https://srvubuclt001.uct.ac.za/optout/out/'. $data['hash'];
            $data['authenticated'] = json_encode($authenticated);
            $data['confirmed'] = json_encode($dept->getDetails());
            $data['details'] = $dept->getDetails();
            $data['readonly'] = ($now->diff(new \DateTime($data['date_course']))->format('%R') == '-');

            // return $this->render('department_out_3_confirmed.html.twig', $data);

            if (!$data['readonly']) {
                if ($authenticated['a']) {
                    // authenticated - show confirm page
                    if ($confirmed) {
                        // show confirmed page
                        return $this->render('department_out_3_confirmed.html.twig', $data);
                    } else {
                        // show confirm choice
                        return $this->render('department_out_2_ask.html.twig', $data);
                    }
                } else {
                    return $this->render('department_out_1_login.html.twig', $data);
                }
            } else {
                return $this->viewFromHash($data['hash'], $request);
            }
        }
    }

    /**
     * @Route("/mail/{hash}")
     */
    public function getMail($hash, Request $request) {
        $utils = new Utilities();

        $data = $utils->getMail($hash);
        //return new Response(json_encode($data), 201);

        if ($data['success']) {
            $data = $data['result'][0];
            // $hash = $data['hash'];

            if ($data['course'] === null ) {
                $dept = new Department($data['dept'], $data['hash'], $data['year'], false);
                $details = $dept->getDetails();

                return $this->render('department_mail.html.twig',
                    array(  'dept' => $data['dept'],
                            'dept_name' => $details['name'],
                            'name' => $data['name'],
                            'date' => $data['date_course'],
                            'out_link' => 'https://srvubuclt001.uct.ac.za/optout/out/'. $data['hash'],
                            'view_link' => 'https://srvubuclt001.uct.ac.za/optout/view/'. $data['hash']));
            } else {
                $course = new Course($data['course'], $data['hash'], $data['year'], false);
                $details = $course->getDetails();

                $vula = new SakaiWebService();
                $site_list = $vula->getSiteByProviderId($data['course'], $data['year']);
                $site = '';
                if (count($site_list) > 0) {
                    $site = $site_list[0]['SITE_ID'];
                }

                $o = array( 'dept' => $data['dept'],
                            'course' => $data['course'],
                            'name' => $data['name'],
                            'site_list' => $site_list,
                            'site' => $site,
                            'date' => $data['date_schedule'],
                            'out_link' => 'https://srvubuclt001.uct.ac.za/optout/out/'. $data['hash'],
                            'view_link' => 'https://srvubuclt001.uct.ac.za/optout/view/'. $data['hash']);

                if ($data['type'] == 'confirm') {
                    switch($data['case']) {
                        case '1':
                        case '2':
                        case '3':
                        case '4':
                        case '5':
                        case '6':
                            return $this->render('course_mail_case_'. $data['case'] .'.html.twig', $o);
                            break;
                        default:
                            return $this->render('course_mail.html.twig', $o);
                            break;
                    }
                } else {
                    return $this->render('course_mail.html.twig', $o);
                }
            }
        } else {
            return new Response("ERROR_MAIL_HASH", 500);
        }
    }

    /**
     * @Route("/mail_series/{hash}")
     */
    public function getSeriesMail($hash, Request $request) {
        $utils = new Utilities();

        // testing
        // if ($hash == 'zzz000') {
        //     $hash = '7885ba';
        // }

        $data = $utils->getSeries($hash);
        // return new Response(json_encode($data), 201);

        if ($data['success']) {
            $data = $data['result'][0];
            // $hash = $data['hash'];

            $batch = (new OpencastRetentionBatch($data['batch']))->getBatch();

            $ocService = new OCRestService();
            $metadata = $ocService->getSeriesMetadata($data['series_id']);
            foreach($metadata as $struct) {
                $tmp = [];
                foreach($struct['fields'] as $field) {
                    $tmp[ str_replace("-","_",$field['id'])] = $field['value'];
                }
                switch ($struct['flavor']) {
                    case 'dublincore/series':
                        $data['dublincore'] = $tmp;
                        break;
                    case 'ext/series':
                        $data['ext'] = $tmp;
                        break;
                }
            }

            // Now to see if the users are still active or not
            $username = $data['creator'];
            $user_status = '';
            if ($data['username'] != "") {
                $data['user'] = (new User($data['username']))->getDetails();
                $username = $data['user']['first_name'] .' '. $data['user']['last_name'];
                $user_status = $data['user']['status'];
            }

            $now = new \DateTime();
            $past_scheduled = ($now->diff($batch['date_scheduled'])->format('%R') == '-');

            $template = 'series_mail.html.ready.twig';
            if ($past_scheduled) {

                // series_mail.html.confirm.twig
                // series_mail.html.confirm-forever.twig
                // series_mail.html.confirm-long.twig
                // series_mail.html.confirm-normal.twig
                $template = 'series_mail.html.confirm-'. $data['retention'] .'.twig';

                switch($user_status) {
                    case 'admin':
                    case 'guest':
                    case 'staff':
                    case 'student':
                    case 'associate':
                    case 'special':
                    case 'thirdparty':
                    case 'user':
                        break;
                    case 'Inactive':
                    case 'inactiveStaff':
                    case 'inactiveStudent':
                    case 'inactiveThirdparty':
                    case 'offer':
                    case 'pace':
                    case 'test':
                    case 'webctImport':
                        $username = Null;
                    break;
                    default:
                        $username = Null;
                    break;
                }
            } else {

                switch ($data['action']) {
                    case 'error': $template  = 'series_mail.html.error.twig'; break;
                }
            }

            $last_email_date = $utils->getLastNotificationRetentionEmail($data['hash']);

            return $this->render($template,
                    array(  'template' => $template,
                            'title' => $data['title'],
                            'series_id' => $data['series_id'],
                            'contributor' => $data['contributor'],
                            'username' => $data['username'],
                            'creator' => $username,
                            'retention' => $data['retention'],
                            'user_status' => $user_status,
                            'no_recordings' => $data['no_recordings'],
                            'expiry_date' => $data['ext']['series_expiry_date'],
                            'site_id' => $data['ext']['site_id'],
                            'global_expiry_date' => $batch['date_scheduled'],
                            'last_email_date' => $last_email_date,
                            'readonly' => $past_scheduled,
                            'view_link' => 'https://srvubuclt001.uct.ac.za/optout/view-series/'. $data['hash']));
        } else {
            return new Response("ERROR_MAIL_HASH", 500);
        }
    }

    /**
     * @Route("/series_tocc/{hash}")
     */
    public function getSeriesMailToCC($hash, Request $request) {
        $utils = new Utilities();
        $data = $utils->getSeries($hash);

        if ($data['success']) {
            $data = $data['result'][0];

            if ($data['username'] != "") {
                $data['user'] = (new User($data['username']))->getDetails();

                $ocService = new OCRestService();
                $metadata = $ocService->getSeriesMetadata($data['series_id']);
                foreach($metadata as $struct) {
                    $tmp = [];
                    foreach($struct['fields'] as $field) {
                        $tmp[ str_replace("-","_",$field['id'])] = $field['value'];
                    }
                    switch ($struct['flavor']) {
                        case 'dublincore/series':
                            $data['dublincore'] = $tmp;
                            break;
                        case 'ext/series':
                            $data['ext'] = $tmp;
                            break;
                    }
                }

                $name = $data['user']['first_name'] .' '. $data['user']['last_name'];
                $to = $data['user']['email'];
                $cc = implode(';', $data['ext']['notification_list']);
                switch($data['user']['status']) {
                    case 'admin':
                    case 'guest':
                    case 'staff':
                    case 'student':
                    case 'associate':
                    case 'special':
                    case 'thirdparty':
                    case 'user':
                        break;
                    case 'Inactive':
                    case 'inactiveStaff':
                    case 'inactiveStudent':
                    case 'inactiveThirdparty':
                    case 'offer':
                    case 'pace':
                    case 'test':
                    case 'webctImport':
                        $to = $cc;
                        $cc = '';
                        $name = '';
                    break;
                }

                return new Response( json_encode(
                        array('to' => $to,
                            'cc' => $cc,
                            'name' => $name,
                            'status' => $data['user']['status'])
                        ), 201);
            }
        }
        return new Response("ERROR_MAIL_HASH", 500);
    }

    /**
     * @Route("/subject/{hash}")
     */
    public function getMailSubject($hash, Request $request) {
        $utils = new Utilities();

        $data = $utils->getMail($hash);
        //return new Response(json_encode($data), 201);

        if ($data['success']) {
            $data = $data['result'][0];

            if ($data['course'] === null ) {
                $dept = new Department($data['dept'], $data['hash'], $data['year'], false);
                $details = $dept->getDetails();

                return new Response("Automated Setup of Lecture Recording: Department Opt-Out process", 201);
            } else {
                $course = new Course($data['course'], $data['hash'], $data['year'], false);
                $details = $course->getDetails();

                $str = $data['course'] ." course: Automated Setup or Opt-out of Lecture Recording" .
                        ($data['type'] == 'confirm' ? ' [Completed]' : '');

                return new Response($str, 201);
            }
        } else {
            return new Response("ERROR_MAIL_HASH", 500);
        }
    }

    /**
     * @Route("/subject_series/{hash}")
     */
    public function getSeriesMailSubject($hash, Request $request) {
        $utils = new Utilities();

        // testing
        // if ($hash == 'zzz000') {
        //     $hash = '7885ba';
        // }

        $data = $utils->getSeries($hash);

        if ($data['success']) {

            $data = $data['result'][0];
            // $hash = $data['hash'];
            $batch = (new OpencastRetentionBatch($data['batch']))->getBatch();
            $str = $data['title'] .": Recording Expiry Notice";

            $now = new \DateTime();
            $past_scheduled = ($now->diff($batch['date_scheduled'])->format('%R') == '-');
            if ($past_scheduled) {
                $str .= " [Deleted]";
            }
            return new Response($str, 201);
        } else {
            return new Response("ERROR_MAIL_HASH", 500);
        }
    }

    /**
     * Main page
     *
     * @Route("/", name="Main")
     */
    public function defaultMain(Request $request) {
	    // $pathInfo = $request->getPathInfo();
        // $requestUri = $request->getRequestUri();

        // $url = $requestUri .'/optout/admin';

	    return $this->redirect('https://srvubuclt001.uct.ac.za/optout/admin');
    }

    /**
     * Show admin page
     *
     * @Route("/admin", name="admin_show")
     */
    public function getAdmin(Request $request) {
        $authenticated = ['a' => false, 'z' => ['success' => 0, 'err' => 'none']];

        $now = new \DateTime();
        $utils = new Utilities();
        $workflow = new Workflow();

        switch ($request->getMethod()) {
            case 'POST':
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');
                try {
                    if ($this->ldapService->authenticate($user, $password)) {
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $user);
                        $authenticated['a'] = true;
                        $z = $utils->getAuthorizedUsers($user);
                        if ($z) {
                            if ($z['success']) {
                                $authenticated['z'] = $z['result'][0];
                                $authenticated['z']['success'] = 1;
                            }
                        }
                    } else {
                        $authenticated['z']['err'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    $this->logger->info("error: " . $e->getMessage());
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['z']['err'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['z']['err'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;
                if ($session->get('username')) {
                    $z = $utils->getAuthorizedUsers($session->get('username'));
                    if ($z) {
                        if ($z['success']) {
                            $authenticated['z'] = $z['result'][0];
                            $authenticated['z']['success'] = 1;
                        }
                    }
                }
            break;
        }
        $this->logger->info("Login attempt: " . json_encode($authenticated));

        if (!isset($authenticated)) {
            $authenticated = '[]';
        }

        $data = [ 'dept' => 'CILT', 'authenticated' => $authenticated, 'workflow' => $workflow->getWorkflow() ];
        //return new Response(json_encode($data), 201);
        //return new Response(json_encode($authenticated['z']), 201);

        if ($authenticated['z']['success']) {

            $data['departments'] = $utils->getAllCourses();

            $ar = [];
            if ($data['departments']['success'] == '1') {
                foreach ($data['departments']['result'] as $a) {
                    array_push($ar, substr($a['dept'], 0, 1));
                }
            }
            $data['list'] = array_unique($ar);

            //$data['courses'] =
            return $this->render('admin.html.twig', $data);
        } else {
            return $this->render('admin_login.html.twig', $authenticated['z']);
        }
    }

    /**
     * Show series admin page
     *
     * @Route("/series", name="series_admin_show")
     */
    public function showSeries(Request $request) {
        $authenticated = ['a' => false, 'z' => ['success' => 0, 'err' => 'none']];

        $now = new \DateTime();
        $utils = new Utilities();

        switch ($request->getMethod()) {
            case 'POST':
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');

                try {
                    if ($this->ldapService->authenticate($user, $password)) {
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $user);
                        $authenticated['a'] = true;
                        $z = $utils->getAuthorizedUsers($user);
                        if ($z) {
                            if ($z['success']) {
                                $authenticated['z'] = $z['result'][0];
                                $authenticated['z']['success'] = 1;
                            }
                        }
                    } else {
                        $authenticated['z']['err'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['z']['err'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['z']['err'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;
                if ($session->get('username')) {
                    $z = $utils->getAuthorizedUsers($session->get('username'));
                    if ($z) {
                        if ($z['success']) {
                            $authenticated['z'] = $z['result'][0];
                            $authenticated['z']['success'] = 1;
                        }
                    }
                }
            break;
        }

        $data = [
            'dept' => 'CILT',
            'authenticated' => $authenticated,
            'batches' => $utils->getAllBatches()
        ];

        if ($authenticated['z']['success']) {
            //return new Response(json_encode($data), 201);
            return $this->render('series.html.twig', $data);
        } else {
            return $this->render('series_login.html.twig', $authenticated['z']);
        }
    }

    /**
     * View the series according to the hash it receives
     *
     * @Route("/view-series/{hash}")
     */
    public function viewSeriesFromHash($hash, Request $request) {
        $authenticated = ['a' => false, 'z' => ['success' => 0, 'err' => 'none']];

        $now = new \DateTime();
        $utils = new Utilities();
        $data = $utils->getSeries($hash);

        if (!$data['success']) {
            return $this->render('error_series.html.twig', $data);
        }
        $data = $data['result'][0];
        // $hash = $data['hash'];

        switch ($request->getMethod()) {
            case 'POST':
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');

                try {
                    if ($this->ldapService->authenticate($user, $password)) {
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $user);
                        $authenticated['a'] = true;
                        $z = $utils->getAuthorizedUsers($user);
                        if ($z) {
                            if ($z['success']) {
                                $authenticated['z'] = $z['result'][0];
                                $authenticated['z']['success'] = 1;
                            }
                        }
                    } else {
                        $authenticated['z'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['z']['err'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['z']['err'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;

                $z = $utils->getAuthorizedUsers($session->get('username'));
                if ($z) {
                    if ($z['success']) {
                        $authenticated['z'] = $z['result'][0];
                        $authenticated['z']['success'] = 1;
                    }
                }
            break;
        }

        // Require logged in user
        if (!$authenticated['a']) {
            //return new Response(json_encode($authenticated), 201);
            return $this->render('series_view_login.html.twig', ['hash' => $data['hash'], 'success' => $authenticated['z']['success'], 'err' => $authenticated['z']['err']]);
        } else {
            if (!isset($authenticated['z'])) {
                $authenticated['z'] = ['type' => 'normal'];
            } else {
                if (!isset($authenticated['z']['type'])) {
                    $authenticated['z']['type'] = 'normal';
                }
            }
        }


        $data['authenticated'] = $authenticated;
        $data['emails'] = $utils->getSeriesEmails($data['hash']);

        $ocService = new OCRestService();
        $metadata = $ocService->getSeriesMetadata($data['series_id']);
        foreach($metadata as $struct) {
            $tmp = [];
            foreach($struct['fields'] as $field) {
                $tmp[ str_replace("-","_",$field['id'])] = $field['value'];
            }
            switch ($struct['flavor']) {
                case 'dublincore/series':
                    $data['dublincore'] = $tmp;
                    break;
                case 'ext/series':
                    $data['ext'] = $tmp;
                    break;
            }
        }

        // Now to see if the users are still active or not
        if ($data['username'] != "") {
            $data['user'] = (new User($data['username']))->getDetails();
        } else {
            $data['user'] = '';
        }

        if (isset($data['ext'])) {
            if (isset($data['ext']['creator_id'])) {
                if ($data['ext']['creator_id'] != "") {
                    $data['user'] = (new User($data['ext']['creator_id']))->getDetails();
                }
            }
        } else {
            $data['ext'] = [];
        }

        $ar = $ocService->getEventsForSeries($data['series_id']);
        if (isset($ar['result'])) {

            $ar['result'] = array_filter($ar['result'], function($obj){
                if (isset($obj['mediaType'])) {
                    return ($obj['mediaType'] == "AudioVisual");
                }
                return false;
            });

            $func = function($event) {

                if ($event['mediaType'] == "AudioVisual") {
                    if (isset($event['mediapackage'])) {
                        $p = $event['mediapackage'];

                        // get preview
                        // $previews = $p['attachments']['attachment'];
                        $previews = array_values(array_filter(
                            array_map(function($track){
                                $pass = false;
                                if ((isset($track["mimetype"])) && (isset($track['type']))) {
                                    $pass = ($track["mimetype"] == "image/jpeg") && strpos($track['type'], "search+preview");
                                    if (isset($track["tags"]) && (gettype($track["tags"]) == "array")) {
                                        if (count($track["tags"]) > 0) {
                                            if (gettype($track["tags"]["tag"]) == 'string') {
                                                $pass = $pass && ($track["tags"]["tag"] == "engage-download");
                                            } else {
                                                $pass = $pass && in_array("engage-download", $track["tags"]["tag"]);
                                            }
                                        }
                                    }
                                }

                                if ($pass) {
                                    return (object) array('flavor' => explode("/", $track['type'])[0],
                                        'url' => str_replace('http:', 'https:', $track['url']),
                                        'ref' => $track['ref']
                                    );
                                }
                            }, $p['attachments']['attachment']), function($item) {
                                return (gettype($item) != "NULL");
                            }));

                        // get downloads
                        $downloads = $p['media']['track'];

                        if (isset($downloads['id'])) {
                            // single media track
                            $track = $downloads;
                            $is_atom = gettype($track["tags"]["tag"]) == "array" ? in_array("atom", $track["tags"]["tag"]) : $track["tags"]["tag"] == "atom";

                            // "engage-download"
                            if (isset($track["mimetype"]) && $is_atom) {
                                if (($track["mimetype"] == "video/mp4") || ($track["mimetype"] == "video/avi")) {
                                    $flavor = explode("/", $track['type'])[0];

                                    $img = array_filter($previews,
                                        function ($e) use (&$flavor) {
                                            return $e->flavor == $flavor;
                                        }
                                    );

                                    $downloads = array(array(
                                        'flavor' => $flavor,
                                        'quality' => implode(array_filter($track["tags"]["tag"], function($str) {
                                                return(strpos($str, 'quality'));
                                            })),
                                        'img' => (count($img) > 0 ? array_values($img)[0]->url :''),
                                        'url' => str_replace('http:', 'https:', $track['url']),
                                        'video' => $track['video']['resolution']
                                    ));
                                }
                            }

                        } else {
                            $downloads = array_filter(
                                array_map(function($track) use (&$previews) {

                                    $is_atom = gettype($track["tags"]["tag"]) == "array" ? in_array("atom", $track["tags"]["tag"]) : $track["tags"]["tag"] == "atom";

                                    // "engage-download"
                                    if (isset($track["mimetype"]) && $is_atom) {
                                        if (($track["mimetype"] == "video/mp4") || ($track["mimetype"] == "video/avi")) {
                                            $flavor = explode("/", $track['type'])[0];

                                            $img = array_filter($previews,
                                                function ($e) use (&$flavor) {
                                                    return $e->flavor == $flavor;
                                                }
                                            );

                                            return (object) array(
                                                'flavor' => $flavor,
                                                'quality' => implode(array_filter($track["tags"]["tag"], function($str) {
                                                        return(strpos($str, 'quality'));
                                                    })),
                                                'img' => (count($img) > 0 ? array_values($img)[0]->url :''),
                                                'url' => str_replace('http:', 'https:', $track['url']),
                                                'video' => $track['video']['resolution']
                                            );
                                        }
                                    }
                                }, $p['media']['track']), function($item) {
                                    return (gettype($item) != "NULL");
                                });
                        }

                        $q_ar = array_column($downloads, 'quality');

                        if (count($q_ar) > 0) {
                            if (array_search('high-quality', $q_ar) !== FALSE) {
                                // we have high quality
                                $downloads = array_filter($downloads, function($item) {
                                    return ($item->quality == "high-quality") || ($item->quality == "");
                                });
                            } elseif (array_search('medium-quality', $q_ar) !== FALSE) {
                                // we have medium quality
                                $downloads = array_filter($downloads, function($item) {
                                    return ($item->quality == "medium-quality") || ($item->quality == "");
                                });
                            } elseif (array_search('low-quality', $q_ar) !== FALSE) {
                                // we have low quality
                                $downloads = array_filter($downloads, function($item) {
                                    return ($item->quality == "low-quality") || ($item->quality == "");
                                });
                            }
                        }

                        usort($downloads, function($a, $b) { return $a->flavor < $b->flavor; });
                        $event['media']  = (object) array('previews' => $previews, 'downloads' => array_values($downloads));
                    }

                    // remove unwanted fields
                    unset($event['mediapackage']);
                    unset($event['ocMediapackage']);
                    unset($event['segments']);
                    unset($event['keywords']);
                    unset($event['score']);
                    unset($event['org']);

                    return $event;
                }
            };

            $event_array = array_values(array_map($func, $ar['result']));
            try {
                usort($event_array, function($a, $b) { return $a['dcCreated'] > $b['dcCreated']; });
            } catch (\Exception $e) { }

            $data['events'] = (object) array('offset' => $ar['offset'], 'limit' => $ar['limit'], 'total' => $ar['total'], //'query' => $ar['query'],
                                            'result' => $event_array);
        } else {
            $data['events'] = $ar;
        }

        // return new Response(json_encode($data), 201);
        return $this->render('series_view.html.twig', $data);
    }

    /**
     * @Route("/survey/")
     */
    public function showSurveyOverview(Request $request) {
        $authenticated = ['a' => false, 'z' => ['success' => 0, 'err' => 'none']];

        $now = new \DateTime();
        $utils = new Utilities();

        switch ($request->getMethod()) {
            case 'POST':
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');

                try {
                    if ($this->ldapService->authenticate($user, $password)) {
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $user);
                        $authenticated['a'] = true;
                        $z = $utils->getAuthorizedUsers($user);
                        if ($z) {
                            if ($z['success']) {
                                $authenticated['z'] = $z['result'][0];
                                $authenticated['z']['success'] = 1;
                            }
                        }
                    } else {
                        $authenticated['z'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['err'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['err'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;

                $z = $utils->getAuthorizedUsers($session->get('username'));
                if ($z) {
                    if ($z['success']) {
                        $authenticated['z'] = $z['result'][0];
                        $authenticated['z']['success'] = 1;
                    }
                }
            break;
        }

        $data = [
            'authenticated' => $authenticated
        ];

        // return new Response(json_encode($data), 201);

        if ($authenticated['z']['success']) {
            return $this->render('results_overview.html.twig', $data);
        } else {
            return $this->render('results_overview_login.html.twig', $authenticated['z']);
        }
    }

    public function outputCSV($data, $useKeysForHeaderRow = true) {
        if ($useKeysForHeaderRow) {
            array_unshift($data, array_keys(reset($data)));
        }

        $outputBuffer = fopen("php://output", 'w');
        foreach($data as $v) {
            fputcsv($outputBuffer, $v);
        }
        fclose($outputBuffer);
    }


    /**
     * View the survey page according to the hash it receives
     *
     * @Route("/downloadsurvey_t/{hash}")
     */
    public function surveyDownloadFromHashForTutors($hash, Request $request) {
        return $this->doSurveyDownload($hash, $request, true);
    }

    /**
     * View the survey page according to the hash it receives
     *
     * @Route("/downloadsurvey/{hash}")
     */
    public function surveyDownloadFromHash($hash, Request $request) {
        return $this->doSurveyDownload($hash, $request, false);
    }

    public function doSurveyDownload($hash, Request $request, $is_tutor = false) {
        $authenticated = ['a' => false, 'z' => 'none'];

        switch ($request->getMethod()) {
            case 'POST':
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');

                try {
                    if ($this->ldapService->authenticate($user, $password)) {
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $user);
                        $authenticated['a'] = true;
                        $z = $utils->getAuthorizedUsers($user);
                        if ($z) {
                            if ($z['success']) {
                                $authenticated['z'] = $z['result'][0];
                                $authenticated['z']['success'] = 1;
                            }
                        }
                    } else {
                        $authenticated['z'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['z'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['z'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;
            break;
        }

        $data = [
            'hash' => $hash,
            'authenticated' => $authenticated,
            'err' => $authenticated['z'],
            'out_link' => '/optout/survey/'. $hash
        ];

         // Require logged in user
        if ($authenticated['a'] === false) {
            return $this->render('results.html.twig', $data);
        }

        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone('Africa/Johannesburg'));

        $utils = new Utilities();
        $data = $utils->getRawSurveyResults($hash, $is_tutor);
        // return new Response(json_encode($data), 201);

        if (!$data['success']) {
            return $this->render('results_error.html.twig', ['err' => $data['result']['err']]);
        }

        // Generate response
        $response = new Response();

        // Set headers
        $response->headers->set('Cache-Control', 'private');
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition',
                'attachment; filename="UCT Survey Results '. $data['code'] . ($is_tutor ? ' Tutors' : '') .' '. $now->format('Y-m-d_H-i'). '.csv"');
        //$response->headers->set('Content-length', length($this->outputCSV($data['result'])));

        // Send headers before outputting anything
        $response->sendHeaders();

        $response->setContent($this->outputCSV($data['result']));
        return $response;
    }

    /**
     * View the survey page according to the hash it receives
     *
     * @Route("/survey/{hash}")
     */
    public function surveyFromHash($hash, Request $request) {
        $authenticated = ['a' => false, 'z' => 'none', 'err' => 'none'];

        $now = new \DateTime();
        $utils = new Utilities();

        switch ($request->getMethod()) {
            case 'POST':
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');

                try {
                    if ($this->ldapService->authenticate($user, $password)) {
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $user);
                        $authenticated['a'] = true;
                        $z = $utils->getAuthorizedUsers($user);
                        if ($z) {
                            if ($z['success']) {
                                $authenticated['z'] = $z['result'][0];
                                $authenticated['z']['success'] = 1;
                            }
                        }
                    } else {
                        $authenticated['z'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['err'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['err'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;

                $z = $utils->getAuthorizedUsers($session->get('username'));
                if ($z) {
                    if ($z['success']) {
                        $authenticated['z'] = $z['result'][0];
                        $authenticated['z']['success'] = 1;
                    }
                }
            break;
        }

        $data = [
            'hash' => $hash,
            'result' => ['course' => $hash],
            'authenticated' => $authenticated,
            'success' => $authenticated['err'] == 'none' ? 1 : 0,
            'err' => $authenticated['err'],
            'out_link' => '/optout/survey/'. $hash
        ];

        // Require logged in user
        if ($authenticated['a'] === false) {
            return $this->render('results.html.twig', $data);
        } else {
            if (!isset($authenticated['z'])) {
                $authenticated['z'] = ['type' => 'normal'];
            } else {
                if (!isset($authenticated['z']['type'])) {
                    $authenticated['z']['type'] = 'normal';
                }
            }
        }
        $data['authenticated'] = $authenticated;
        // return new Response(json_encode($data), 201);

        $data['result'] = $utils->getSurveyResults($hash);
        $data['out_link'] = 'https://srvubuclt001.uct.ac.za/optout/downloadsurvey/'. $hash .'?t='. time();
        $data['out_link_t'] = 'https://srvubuclt001.uct.ac.za/optout/downloadsurvey_t/'. $hash .'?t='. time();

        // return new Response(json_encode($data), 201);
        if (!$data['result']['success']) {
            return $this->render('results_error.html.twig', ['err' => $data['result']['err']]);
        }
        return $this->render('results.html.twig', $data);
    }

    /**
     * View the survey page according to the hash it receives
     *
     * @Route("/survey_test/{hash}")
     */
    public function surveyFromHashTest($hash, Request $request) {
        $authenticated = ['a' => false, 'z' => 'none', 'err' => 'none'];

        $now = new \DateTime();
        $utils = new Utilities();

        switch ($request->getMethod()) {
            case 'POST':
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');

                try {
                    if ($this->ldapService->authenticate($user, $password)) {
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $user);
                        $authenticated['a'] = true;
                        $z = $utils->getAuthorizedUsers($user);
                        if ($z) {
                            if ($z['success']) {
                                $authenticated['z'] = $z['result'][0];
                                $authenticated['z']['success'] = 1;
                            }
                        }
                    } else {
                        $authenticated['z'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['err'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['err'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;

                $z = $utils->getAuthorizedUsers($session->get('username'));
                if ($z) {
                    if ($z['success']) {
                        $authenticated['z'] = $z['result'][0];
                        $authenticated['z']['success'] = 1;
                    }
                }
            break;
        }

        $data = [
            'hash' => $hash,
            'result' => ['course' => $hash],
            'authenticated' => $authenticated,
            'success' => $authenticated['err'] == 'none' ? 1 : 0,
            'err' => $authenticated['err'],
            'out_link' => '/optout/survey/'. $hash
        ];

        // Require logged in user
        if ($authenticated['a'] === false) {
            return $this->render('results.html.twig', $data);
        } else {
            if (!isset($authenticated['z'])) {
                $authenticated['z'] = ['type' => 'normal'];
            } else {
                if (!isset($authenticated['z']['type'])) {
                    $authenticated['z']['type'] = 'normal';
                }
            }
        }
        $data['authenticated'] = $authenticated;
        // return new Response(json_encode($data), 201);

        $data['result'] = $utils->getSurveyResults($hash);
        $data['out_link'] = 'https://srvubuclt001.uct.ac.za/optout/downloadsurvey/'. $hash .'?t='. time();
        $data['out_link_t'] = 'https://srvubuclt001.uct.ac.za/optout/downloadsurvey_t/'. $hash .'?t='. time();

        // return new Response(json_encode($data), 201);
        if (!$data['result']['success']) {
            return $this->render('results_error.html.twig', ['err' => $data['result']['err']]);
        }
        return $this->render('results_test.html.twig', $data);
    }

    /**
     * @Route("/mail_subject_results/{hash}")
     */
    public function getMailSubjectForResults($hash, Request $request) {
        $utils = new Utilities();
        $data = $utils->getSurveyForEmail($hash);

        if ($data['success']) {

            $str = "Student Access Survey for ". $data['code']; //." as of ". (new \DateTime($data['updated_at']))->format('jS F Y g:ia');
            //$en = $utils->encryptHash($hash);
            //$str = "|". $en .'|'. $utils->decryptHash($en).'|';
            return new Response($str, 201);
        } else {
            return new Response("ERROR_MAIL_HASH", 500);
        }
    }

    /**
     * @Route("/mail_body_results/{hash}")
     */
    public function getMailBodyForResults($hash, Request $request) {
        $utils = new Utilities();
        $data = $utils->getSurveyForEmail($hash);

        if ($data['success']) {

            return $this->render('results_mail.html.twig', $data);
        } else {
            return new Response("ERROR_MAIL_HASH", 500);
        }
    }

    /**
     * @Route("/generate_result_emails/")
     */
    public function generateResultEmails(Request $request) {
        $utils = new Utilities();
        $data = $utils->generateResultEmails();

        return new Response(json_encode($data), 201);
    }
}

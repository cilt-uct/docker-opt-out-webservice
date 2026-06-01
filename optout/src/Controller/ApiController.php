<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;

use Psr\Log\LoggerInterface;

use App\Entity\Course;
use App\Entity\Department;
use App\Entity\OpencastRetentionBatch;
use App\Entity\OrganisationalEntityFactory;
use App\Entity\User;
use App\Entity\OpencastSeries;
use App\Entity\Workflow;

use App\Service\D2LWebService;
use App\Service\LDAPService;
use App\Service\OCRestService;
use App\Service\SakaiWebService;
use App\Service\Utilities;

class ApiController extends Controller
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
     * @Route("/search/{searchStr}")
     */
    public function search($searchStr, Request $request) {
        $session = $request->hasSession() ? $request->getSession() : new Session();
        $authenticated = $session->get('username') ? true : false;

        try {
            $isUctEmail = filter_var($searchStr, FILTER_VALIDATE_EMAIL) && strpos($searchStr, '@uct.ac.za') > -1;
            $isNumeric = is_numeric($searchStr);

            $result = [];
            if ($authenticated) {
                if ($isUctEmail) {
                    $result['vula'] = $this->searchVula(null, null, $searchStr);
                    $result['ldap'] = $this->ldapService->findUserByCN($result['vula']['username']);
                    $result['d2l'] = $this->d2l->search($searchStr);
                }
                else if ($isNumeric) {
                    $result['ldap'] = $this->ldapService->findUserByCN($searchStr);
                    $result['vula'] = $this->searchVula($searchStr, null, null);
                    $result['d2l'] = $this->d2l->search($searchStr);
                }
                // $this->logger->info(json_encode($result));
            } else {
                $result = ['authenticated' => false];
            }

            return new Response(
                json_encode($result),
                200,
                [ 'Content-Type' => 'application/json' ]
            );
        } catch (\Exception $e) {
            $response = [
                "text" => "Server error",
                "statusCode" => 500,
                "contentType" => [
                'Content-Type' => 'text/plain'
                ]
            ];
            switch($e->getMessage()) {
                case "no such user":
                $response['text'] = 'User not found';
                $response['statusCode'] = 404;
            }

            return new Response($response['text'], $response['statusCode'], $response['contentType']);
        }
    }

    /**
     * @Route("/search/vula/{searchStr}")
     */
    public function searchVulaEndpoint($searchStr, Request $request) {
        $session = $request->hasSession() ? $request->getSession() : new Session();
        $authenticated = $session->get('username') ? true : false;

        try {
            $result = [];
            if ($authenticated) {
                $result['vula'] = $this->searchVula(null, null, $searchStr);
                if (isset($result['vula']['username'])) {
                    $result['ldap'] = $this->searchLdap($result['vula']['username']);
                }
            } else {
                $result = ['authenticated' => false];
            }

            return new Response(
                json_encode($result),
                    200,
                [
                    'Content-Type' => 'application/json'
                ]
            );
        } catch (\Exception $e) {
            $response = [
                "text" => "Server error: ". $e->getMessage(),
                "statusCode" => 500,
                "contentType" => [
                'Content-Type' => 'text/plain'
                ]
            ];
            switch($e->getMessage()) {
                case "no such user":
                $response['text'] = 'User not found';
                $response['statusCode'] = 404;
            }

            return new Response($response['text'], $response['statusCode'], $response['contentType']);
        }
    }

    /**
     * @Route("/search/d2l/list/{searchStr}")
     */
    public function listD2L($searchStr, Request $request) {
        $session = $request->hasSession() ? $request->getSession() : new Session();
        $authenticated = $session->get('username') ? true : false;
        if ($authenticated) {
            return new Response(
                json_encode($this->d2l->list($searchStr)),
                    200,
                [
                    'Content-Type' => 'application/json'
                ]
            );
        }
        return new Response('[]', 200, ['Content-Type' => 'application/json']);
    }

    private function searchLdap($searchStr) {
        return $this->ldapService->findUserByCN($searchStr);
    }

    private function searchVula($eid, $ip = null, $email = null) {
        return (new User($eid, $ip, $email))->getDetails();
    }

    //   private function searchD2L($searchStr) {
    //     return (new User($eid, $ip, $email))->getDetails();
    //   }

  /**
   * @Route ("/me")
   */
  public function me(Request $request) {
    $session = $request->hasSession() ? $request->getSession() : new Session();
    return new Response(json_encode($session->all()), 200, ['Content-Type' => 'application/json']);
  }

  /**
   * @Route("/authenticate")
   */
  public function auth(Request $request) {
    switch ($request->getMethod()) {
      case 'POST':
        return $this->handleAuthentication($request);
        break;

      default:
        return $this->displayAuthenticationForm($request);
    }
  }

  /**
   * @Route("/api/v0/dept/{deptName}")
   */
  public function departmentEndpoint($deptName, Request $request) {
    switch ($request->getMethod()) {
        case 'PUT':
            return $this->updateDeptOptoutStatus($deptName, $request);
            break;

        case 'PATCH':
            return $this->updateDeptCourses($deptName, $request);
            break;

        default:
            return $this->getDepartmentInfo($deptName, $request);
            break;
    }
  }

  private function getDepartmentInfo($deptName, Request $request) {
    $deptHash = urldecode($request->headers->get('x-entity-hash'));
    $fullCourse = urldecode($request->headers->get('x-entity-courses')) == '1';
    try {
      $department = new Department($deptName, $deptHash, '', false, !$fullCourse);
    } catch (\Exception $e) {
      switch($e->getMessage()) {
        case 'no such dept':
          return new Response('No such dept', 404, ['Content-Type' => 'text/plain']);
          break;

        case 'invalid hash':
          return new Response('invalid hash', 401, ['Content-Type' => 'text/plain']);
          break;

        default:
          return new Response($e->getMessage(), 500, ['Content-Type' => 'text/plain']);
      }
    }

    $result = $department->getDetails();
    $result['fullCourse'] = $fullCourse;
    return new Response(json_encode($result), 200, ['Content-Type' => 'application/json']);
  }

  private function updateDeptCourses($deptName, $request) {
    $session = $request->hasSession() ? $request->getSession() : new Session();
    if (!$session->get('username')) {
      return new Response("Please login to make changes", 401, ['Content-Type' => 'text/plain']);
    }

    $deptHash = urldecode($request->headers->get('x-entity-hash'));
    $data = json_decode($request->getContent(), true);
    $workflow = (new Workflow)->getWorkflow();

    /*
    $data = [{
      "course": course_code,
      "changes": [{
          "field": name (convenorName / convenorEmail),
          "from": old value,
          "to": new value
        }]
    }]
    */

    $conflicts = [];
    $someSuccess = false;

    if (!is_array($data)) {
      return new Response("Please supply changes as a JSON array", 400, ['Content-Type' => 'text/plain']);
    }

    try {
      // get full department with all courses
      $dept = new Department($deptName, $deptHash, null, false, false);

      $courseCodesToUpdate = array_map(function($change) {
        if (isset($change['course'])) {
          return $change['course'];
        }
      }, $data);

      //if ($courseCodesToUpdate)

      $coursesToUpdate = array_filter($dept->courses, function($course) use ($courseCodesToUpdate) {
        return in_array($course->courseCode, $courseCodesToUpdate);
      });

      $coursesToUpdate = array_reduce($coursesToUpdate, function($result, $course) {
        $result[$course->courseCode] = $course;
        return $result;
      }, []);

      // loop through values
      foreach ($data as $index => $update) {

        // we want to change a course value
        if (isset($update['course'])) {

          // nope we don't have that course :p
          if (!isset($coursesToUpdate[$update['course']])) {
            continue;
          }

          try {
              // get the correct course and run update on it
              $coursesToUpdate[$update['course']]->updateCourse($update['changes'], $session->get('username'), $workflow['oid']);
              $someSuccess = true;
          } catch (\Exception $e) {
              switch($e->getMessage()) {
                  case 'conflict':
                      $coursesToUpdate[$update['course']]->fetchDetails();
                      $conflicts[] = $coursesToUpdate[$update['course']]->getDetails();
                      $conflicts[] = [
                        'changes' => $update['changes'],
                        'username' => $session->get('username'),
                        'wid' => $workflow['oid']
                      ];
                      break;
                  default:
                      throw new \Exception($e->getMessage());
              }
          }
        } // if updating

        // we want to change the dept value
        if (isset($update['dept'])) {

          try {
            // get the correct course and run update on it
            $dept->updateDepartment($update['changes'], $session->get('username'));
            $someSuccess = true;
          } catch (\Exception $e) {
            switch($e->getMessage()) {
                case 'conflict':
                    $conflicts[] = $dept->getDetails();
                    break;
                default:
                    throw new \Exception($e->getMessage());
            }
          }
        } // if updating dept
      } // foreach
    } catch (\Exception $e) {
      $statusCode = 500;
      switch($e->getMessage()) {
          case 'Authorisation required':
              $statusCode = 401;
              break;
      }
      return new Response($e->getMessage(), $statusCode, ['Content-Type' => 'text/plain']);
    }

    if (sizeof($conflicts) === 0) {
      return new Response('', 204);
    }
    else if ($someSuccess) {
      return new Response(json_encode($conflicts), 200, ['Content-Type' => 'application/json']);
    }
    else {
      return new Response(json_encode($conflicts), 409, ['Content-Type' => 'application/json']);
    }
  }

  private function updateDeptOptoutStatus($deptName, Request $request) {
    $session = $request->hasSession() ? $request->getSession() : new Session();
    $deptHash = urldecode($request->headers->get('x-entity-hash'));
    $data = json_decode($request->getContent(), true);
    $workflow = (new Workflow)->getWorkflow();

    try {
      $dept = new Department($deptName, $deptHash, null, false);
      $updateStatus = $dept->updateOptoutStatus($session->get('username'), $data, $workflow['oid']);
    } catch(\Exception $e) {
      $statusCode = 500;
      switch($e->getMessage()) {
          case 'Authorisation required':
              $statusCode = 401;
              break;
      }
      return new Response($e->getMessage(), $statusCode, ['Content-Type' => 'text/plain']);
    }
    return new Response(json_encode($updateStatus), 201);
  }

  private function updateCourseOptoutStatus($courseCode, Request $request) {
    $session = $request->hasSession() ? $request->getSession() : new Session();
    $courseHash = urldecode($request->headers->get('x-entity-hash'));
    $data = json_decode($request->getContent(), true);
    $workflow = (new Workflow)->getWorkflow();

    try {
      $course = new Course($courseCode, $courseHash, null, false);
      $updateStatus = $course->updateOptoutStatus($session->get('username'), $data, $workflow['oid']);
      return new Response(json_encode($course->getDetails()), 201);

    } catch(\Exception $e) {
      $statusCode = 500;
      switch($e->getMessage()) {
          case 'Authorisation required':
              $statusCode = 401;
              break;
      }
      return new Response($e->getMessage(), $statusCode, ['Content-Type' => 'text/plain']);
    }
    return new Response(json_encode($updateStatus), 201);
  }

  /**
   * @Route("/course")
   */
  public function refreshCourses(Request $request) {
    switch($request->getMethod()) {
      case 'PUT':
        $utils = new Utilities();
        try {
            $updateResults = $utils->refreshCourses();
            return new Response(json_encode($updateResults), 200, [
                'Content-Type' => 'text/plain'
              ]
            );
        } catch (\Exception $e) {
          return new Response($e, 500);
        }
        break;

      default:
        return new Response('Only PUT supported right now', 405);
    }
  }

  /**
   * @Route("/departments")
   */
  public function refreshDepartments(Request $request) {
    switch($request->getMethod()) {
      case 'PUT':
        $utils = new Utilities();
        try {
            $updateResults = $utils->refreshDepartments();
            return new Response(json_encode($updateResults), 200, [
                'Content-Type' => 'text/plain'
              ]
            );
        } catch (\Exception $e) {
          return new Response($e->getMessage, 500);
        }
        break;

      default:
        return new Response('Only PUT supported right now', 405);
    }
  }

  /**
   * @Route("/api/v0/{entityType}/{entityName}/hash");
   */
  public function getEntityHash($entityType, $entityName, Request $request) {
    try {
      $entity = OrganisationalEntityFactory::getEntity($entityType, $entityName);
    }
    catch (\Exception $e) {
      switch($e->getMessage()) {
        case 'no such dept':
          return new Response('No such dept', 404, ['Content-Type' => 'text/plain']);
          break;

        case 'invalid hash':
          return new Response('Authorisation required', 401, ['Content-Type' => 'text/plain']);
          break;

        default:
          return new Response($e->getMessage(), 500, ['Content-Type' => 'text/plain']);
      }
    }

    return new Response($entity->getHash(), 200, ['Content-Type' => 'text/plain']);
  }

  /**
   * @Route("/api/v0/timetable/{courseCode}/{year}")
   *
   * THIS DOESN'T WORK
   */
  public function processMail($courseCode, $year, Request $request) {

    // retrieve timetable information
    $json = file_get_contents('https://srvubuclt001.uct.ac.za/timetable/?course='. $courseCode .','. $year);
    $result = json_decode($json);

    if (json_last_error() == JSON_ERROR_NONE) {
      return new Response($json, 201);
    }

    return new Response('Error loading timetable', 201);
  }

  /**
   * @Route("/api/v0/course/{courseCode}")
   */
  public function courseEndpoint($courseCode, Request $request) {
    switch ($request->getMethod()) {
      case 'GET':
        return $this->getCourse($courseCode, $request);

      case 'PUT':
        return $this->updateCourseOptoutStatus($courseCode, $request);

      default:
        return new Response('Method not implemented', 405, ['Content-Type' => 'text/plain']);

    }
  }

  private function getCourse($courseCode, Request $request) {
    $courseHash = urldecode($request->headers->get('x-entity-hash'));
    $year = date('Y');
    $vula = new SakaiWebService();
    $ocService = new OCRestService();
    try {
      $course = new Course($courseCode, $courseHash, $year, false);
      $response = $course->getDetails();
      $response['hasVulaSite'] = $vula->hasProviderId($courseCode, $year);
      $response['hasOCSeries'] = $ocService->hasOCSeries($courseCode, $year);
      $response['isTimetabled'] = $response['hasOCSeries'] ? $course->checkIsTimetabledInOC() : false;
      return new Response(json_encode($response), 200, ['Content-Type' => 'application/json']);
    } catch (\Exception $e) {
      return new Response($e->getMessage(), 500);
    }
  }

  private function handleAuthentication($request) {
    $ldap = new LDAPService();
    $user = $request->request->get('username');
    $password = $request->request->get('password');

    try {
      if ($this->ldapService->authenticate($user, $password)) {
        $session = $request->hasSession() ? $request->getSession() : new Session();
        $session->set('username', $user);
        return new Response("OK", 200);
      }

      return new Response("Invalid username/password combination", 401);

    } catch (\Exception $e) {
      var_dump($e);
      $response = [
        "text" => "Server error",
        "statusCode" => 500,
        "contentType" => [
          'Content-Type' => 'text/plain'
        ]
      ];
      switch ($e->getMessage()) {
        case 'no such user':
          $response['text'] = 'No such user';
          $response['statusCode'] = 404;
          break;

        case 'invalid id':
          $response['text'] = 'Please use your official UCT staff number';
          $response['statusCode'] = 400;
          break;
      }
      return new Response($response['text'], $response['statusCode'], $response['contentType']);
    }
  }

  private function displayAuthenticationForm(Request $request) {
    return new Response("<form method=post><p><input name=username /></p><p><input name=password type=password /></p><button>Login</button></form>");
  }

  /**
   * @Route("/monitor_batch")
   */
  // Retention Service
  public function monitor_batch(Request $request) {
        $max_execution_time = ini_get('max_execution_time');
        ini_set('max_execution_time', 3600);
        set_time_limit(3600);

        switch ($request->getMethod()) {
            case 'GET':
                set_time_limit(30);
                ini_set('max_execution_time', $max_execution_time);
                return $this->runBatchMonitor($request);
            default:
                set_time_limit(30);
                ini_set('max_execution_time', $max_execution_time);
                return new Response('Only GET supported right now', 405);
        }
  }

  private function runBatchMonitor(Request $request) {
    $result = (new OpencastRetentionBatch)->run();

    return new Response(json_encode($result), 201);
  }

  /**
   * @Route("/monitor")
   */
  // opt-out service
  public function monitor(Request $request) {
    set_time_limit(3000);
    switch ($request->getMethod()) {
        case 'GET':
            return $this->runWorkflowMonitor($request);
            break;
        default:
            return new Response('Only GET supported right now', 405);
    }
    set_time_limit(30);
  }

  private function runWorkflowMonitor(Request $request) {
    $result = (new Workflow)->run();
    return new Response(json_encode($result), 201);
  }

  /**
   * @Route("/api/v0/series/")
   */
  public function getSeries(Request $request) {
    $utils = new Utilities();

    // GET /api/v0/series/?order=series,asc&limit=20
    switch ($request->getMethod()) {
      case 'GET':
        try {
          $order = explode(',', $request->query->get('order') != null ? $request->query->get('order') : "title,asc");
          $filter = urldecode($request->query->get('filter') != null ? $request->query->get('filter') : "");
          $page = $request->query->get('page') != null ? $request->query->get('page') : 1;
          $limit = $request->query->get('limit') != null ? $request->query->get('limit') : 15;
          $ret = $request->query->get('ret') != null ? $request->query->get('ret') : "all";
          $act = $request->query->get('act') != null ? $request->query->get('act') : "none";
          $batch = $request->query->get('batch') != null ? $request->query->get('batch') : 0;
          $offset = ($page - 1) * $limit;

          if (count($order) != 2) {
            $order = ['title','asc'];
          }

          $response = $utils->getAllSeries($offset, $limit, $order[1], $order[0], $filter, $ret, $batch, $act);
          return new Response(json_encode($response), 200, ['Content-Type' => 'application/json']);
        } catch (\Exception $e) {
          return new Response($e->getMessage(), 500);
        }
      case 'PATCH':
        $session = $request->hasSession() ? $request->getSession() : new Session();
        if (!$session->get('username')) {
          return new Response("Please login to make changes", 401, ['Content-Type' => 'text/plain']);
        }

        $data = json_decode($request->getContent(), true);
        if (isset($data['action'])) {
          $series = $utils->getSeries($data['hash']);

          switch($data['action']) {
              case "new_notfication":
                if (isset($series['success'])) {
                  if ($series['success'] == "1") {

                    $series = $series['result'][0];

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

                      $ocService->updateRetention($series['series_id'], $series['ext']['retention_cycle'], $dt->format("Y-m-d") ."T00:00:00.000Z", $session->get('username'));
                    }

                    (new OpencastSeries($series['series_id']))->updateRetention($series['ext']['retention_cycle'], $session->get('username'));
                    $data['success'] = $utils->addSeriesEmails($series['series_id'], $data['hash'], $series['batch'],
                                                                $series['user']['first_name'] .' '. $series['user']['last_name'],
                                                                $series['user']['email'],  implode(';', $series['ext']['notification_list']));
                  }
                }
                $data['series'] = $series;
                break;
              case "retention":
                $success = false;
                if (isset($series['success'])) {
                  if ($series['success'] == "1") {
                    $series = $series['result'][0];

                    $year_adjust = $data['val'] == 'forever' ? 50 : (($data['val'] == 'normal' ? 4 : 8));

                    $dt = new \DateTime($series['last_recording']);
                    $dt->modify('+'. $year_adjust  .' years');
                    $data['date'] = $data['val'] == 'forever' ? 'forever' : $dt->format("Y-m-d");

                    $ocService = new OCRestService();
                    $data['result'] = $ocService->updateRetention($series['series_id'], $data['val'], $dt->format("Y-m-d") ."T00:00:00.000Z", $session->get('username'));
                    $success = $data['result']['success'];

                    if ($success) {
                      $success = (new OpencastSeries($series['series_id']))->updateRetention($data['val'], $session->get('username'));
                    }
                  }
                }
                $data['success'] = $success;
                break;
              case "notification-list":
                $success = false;
                if (isset($series['success'])) {
                  if ($series['success'] == "1") {
                    $series = $series['result'][0];

                    $ocService = new OCRestService();
                    $data['result'] = $ocService->updateNotificationList($series['series_id'], $data['val']);
                    $success = $data['result']['success'];

                    if ($success) {
                      $success = (new OpencastSeries($series['series_id']))->updateNotification($data['val'], $session->get('username'));
                    }
                  }
                }
                $data['success'] = $success;
                break;
              default: break;
          }
        }
        return new Response(json_encode($data), 200, ['Content-Type' => 'application/json']);
    default:
        return new Response('Method not implemented', 405, ['Content-Type' => 'text/plain']);
    }
  }

  /**
   * @Route("/api/v0/episode/{eventId}")
   */
  public function getEpisode($eventId, Request $request) {
    switch ($request->getMethod()) {
    // https://mediadev.uct.ac.za/search/episode.json?id=596ff927-79bc-4a15-a39f-80ea8c7f16e0
      case 'GET':
        $ocService = new OCRestService();
        try {
          $response = $ocService->getEventForPlayback(str_replace('_','-',$eventId));
          return new Response(json_encode($response), 200, ['Content-Type' => 'application/json']);
        } catch (\Exception $e) {
          return new Response($e->getMessage(), 500);
        }
    default:
        return new Response('Method not implemented', 405, ['Content-Type' => 'text/plain']);
    }
  }

  /**
   * @Route("/api/v0/dass/")
   */
  public function getSurveyResultEmails(Request $request) {
    $utils = new Utilities();

    // GET /api/v0/series/?order=series,asc&limit=20
    switch ($request->getMethod()) {
      case 'GET':
        try {
          $order = explode(',', $request->query->get('order') != null ? $request->query->get('order') : "title,asc");
          $filter = urldecode($request->query->get('filter') != null ? $request->query->get('filter') : "");
          $page = $request->query->get('page') != null ? $request->query->get('page') : 1;
          $limit = $request->query->get('limit') != null ? $request->query->get('limit') : 15;
          $type = $request->query->get('type') != null ? $request->query->get('type') : "all";
          $state = $request->query->get('state') != null ? $request->query->get('state') : "all";
          $offset = ($page - 1) * $limit;

          if (count($order) != 2) {
            $order = ['code','asc'];
          }

          $response = $utils->getResultEmails($offset, $limit, $order[1], $order[0], $filter, $type, $state);
          return new Response(json_encode($response), 200, ['Content-Type' => 'application/json']);
        } catch (\Exception $e) {
          return new Response($e->getMessage(), 500);
        }
    default:
        return new Response('Method not implemented', 405, ['Content-Type' => 'text/plain']);
    }
  }

}

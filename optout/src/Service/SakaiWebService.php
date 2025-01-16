<?php

namespace App\Service;

use Symfony\Component\Dotenv\Dotenv;

class SakaiWebService
{

    private $host;
    private $user;
    private $pass;
    private $token;
    private $dbh;

    private $loginEndpoint;
    private $sakaiEndpoint;
    private $uctEndpoint;

    public function __construct() {
        //Get environment variables
        $dotenv = new Dotenv();
        $dotenv->load('.env');

        //Get credentials
        $this->host = getenv('VULA_HOST');
        $this->user = getenv('VULA_USER');
        $this->pass = getenv('VULA_PASS');

        $sakaiSoapUrl = "{$this->host}/sakai-ws/soap/sakai?wsdl";
        $uctSoapUrl = "{$this->host}/sakai-ws/soap/uct?wsdl";
        $loginUrl = "{$this->host}/sakai-ws/soap/login?wsdl";

        //Hold onto SOAP endpoints
        $ssl_context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        $this->loginEndpoint = new \SoapClient($loginUrl, array('exceptions' => 0, 'trace' => 1, 'stream_context' => $ssl_context));
        $this->sakaiEndpoint = new \SoapClient($sakaiSoapUrl, array('exceptions' => 0, 'trace' => 1, 'stream_context' => $ssl_context));
        $this->uctEndpoint = new \SoapClient($uctSoapUrl, array('exceptions' => 0, 'trace' => 1, 'stream_context' => $ssl_context));

        //Connect to Vula DB
        $dbhost = getenv('DB_HOST');
        $dbport = getenv('DB_PORT');
        $dbname = getenv('DB_NAME');
        $dbuser = getenv('DB_USER');
        $dbpass = getenv('DB_PASS');
        $dbopts = [
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ];
        try {
            $this->dbh = new \PDO("mysql:host=$dbhost;dbname=$dbname;port=$dbport;charset=utf8mb4", $dbuser, $dbpass, $dbopts);
        } catch (\PDOException $e) {
            var_dump($e);
            echo "cant connect to db";
            $this->dbh = null;
        }

        $this->loginToServer();
    }

    private function loginToServer() {
        //Get token after login
        //Token is authorization for all future calls
        $this->token = $this->loginEndpoint->login($this->user, $this->pass);
        if (is_soap_fault($this->token)) {
             var_dump($this->token->faultstring);
//            throw new \Exception($this->token->faultstring);
        }
    }

    private function logout() {
        $this->loginEndpoint->logout($this->token);
    }

    public function checkUserByEid(string $eid) {
        $details = $this->sakaiEndpoint->checkForUser($this->token, $eid);
        if (is_soap_fault($details)) {
            throw new \Exception($details->faultstring);
        }

        return $details;
    }

    public function getSiteByProviderId(string $courseCode, string $year) {
        $qry = "select A.SITE_KEY, B.SITE_ID, B.TITLE from vula_archive.SAKAI_SITE_PROVIDER_LINK A
                  join vula_archive.SAKAI_SITE_ARCHIVE B on A.SITE_KEY = B.KEY
                  join vula_archive.SAKAI_SITE C on B.SITE_ID = C.SITE_ID and C.PUBLISHED = 1
                where A.PROVIDER_ID = :providerId";

        try {
            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([
                ':providerId' => "$courseCode,$year"
            ]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            var_dump($e);
            throw new \Exception($e->getMessage());
        }
    }

    public function hasProviderId(string $courseCode, string $year) {
        $providers = $this->getSiteByProviderId($courseCode, $year);


        $checkQry = "select SITE_KEY from vula_archive.SAKAI_SITE_PROVIDER_LINK where SITE_KEY = :key";
        $stmt = $this->dbh->prepare($checkQry);
        $providers = array_filter($providers, function($provider) use ($stmt) {
                       //filter out those sites which are providers to plenty of dept courses
                       $stmt->execute([':key' => $provider['SITE_KEY']]);
                       return $stmt->rowCount() < 3;
                     });

        return sizeof($providers) > 0;
    }

    public function getUserByEmail(string $email) {
        $emailQry = "select B.eid, A.agent_uuid from SAKAI_PERSON_T A join SAKAI_USER_ID_MAP B on A.agent_uuid = B.user_id where A.mail = ? limit 1";
        $stmt = $this->dbh->prepare($emailQry);
        $stmt->execute([$email]);
        $record = $stmt->fetch();

        if (!$record || !sizeof(array_keys($record))) {
            return null;
        }

        $user = [
            'email' => $email,
            'organisationalId' => $record['eid'],
            'sakaiUserId' => $record['agent_uuid'],
            'homeSite' => $this->getUserHome($record['eid'])
        ];
        return $user;
    }

    public function getOBStool(string $eid, string $homeSite = null) {
        if (!$homeSite) {
            $homeSite = $this->getUserHome($eid);
        }

        $siteToolsResponse = $this->sakaiEndpoint->getPagesAndToolsForSite($this->token, $eid, $homeSite);
        if (is_soap_fault($siteToolsResponse)) {
            throw new \Exception($siteToolsResponse->faultstring);
        }
        $siteTools = $this->xmlToArray($siteToolsResponse);
        $obsTool = array_values(
                       array_filter(
                           array_filter($siteTools['pages']['page'], function($page) {
                               return isset($page['tools']['tool']) && !$this->is_sequential($page['tools']['tool']);
                           }),
                           function($page) {
                               if (!isset($page['tools']['tool'])) return false;

                               return $page['tools']['tool']['tool-title'] === 'One Button Studio'; //People may change the title. check against tool-id rather?
                           }
                       )
                   );

        if (!sizeof($obsTool)) {
            return false;
        }

        return $obsTool[0];
    }

    public function addOBStoolToSite($eid, $seriesId, $siteId = null, $ltiLauchUrl = 'https://media.uct.ac.za/lti') {
        if (!$siteId) {
            $siteId = $this->getUserHome($eid);
        }

        $creation = $this->uctEndpoint->addExternalToolToSite(
                        //token
                        $this->token,
                        //site id
                        $siteId,
                        //Title of tool
                       'My Videos',
                        //lti launch url
                       'https://media.uct.ac.za/lti',
                       //parameters
                       "sid=$seriesId&type=personal;tool=/ltitools/manage"
        );
        if (is_soap_fault($creation)) {
            throw new \Exception($creation->faultstring);
        }

        return $creation;
    }

    public function getUserHome($eid) {
        $attempts = 0;
        do {
          $siteDetails = $this->sakaiEndpoint->getAllSitesForUser($this->token, $eid);
          if (is_soap_fault($siteDetails)) {
            $this->loginToServer();
            $attempts++;
            var_dump($this->sakaiEndpoint->__getLastResponseHeaders());
          }
        } while (is_soap_fault($siteDetails) && $attempts < 3);

        if ($attempts === 3 && is_soap_fault($siteDetails)) {
            var_dump("still an error");
            return '';
        }

        $sites = $this->xmlToArray($siteDetails)['item'];

        //$sites is either an array or associative. Determine $homeSite in both cases
        $homeSite = array_keys($sites) !== range(0, count($sites) - 1) ? $sites :
                    array_values(array_filter($sites, function($site) {
                        return isset($site['siteTitle']) && $site['siteTitle'] === 'Home' && strpos($site['siteId'], '~') > -1;
                    }))[0];

        return $homeSite['siteId'];
    }

    public function prepareAttendeeSites($attendees) {
        if (!is_array($attendees)) {
            //Payload is the result of an outlook notification in this case
            //Prepare payload prior to follow on steps
            $attendees = json_decode($attendees, true);
        }

        $prepared = [];
        foreach($attendees as $key => $attendee) {
            try {
                $this->prepareSite($attendee);
            } catch (\Exception $e) {
                var_dump($e);
            }
        }

        return $prepared;
    }

    public function prepareOrganiserSite($organiser) {
        return $this->prepareSite($organiser);
    }

    public function prepareSite($presenter) {
        $email = $presenter['emailAddress']['address'];
        $name = $presenter['emailAddress']['name'];

        $user = $this->dbh ? $this->getUserByEmail($email) : $this->checkUserByEid($email);

        if (!$user) {
            return "no such user";
        }
        if (!isset($user['homeSite']) || empty($user['homeSite'])) {
            return "cant find user site";
        }

        $eid = $user['organisationalId'];
        $homeSite = $this->getUserHome($eid);
//        return $homeSite;
        return $this->addOBStool($name, $email, $eid, $homeSite);
    }

    public function addOBStool($name, $email, $orgId, $sakaiSite) {
        $series = $this->createOCSeries($name, "$name ($email)", $orgId, $sakaiSite);
        if (!$series) {
            return ['user' => $orgId, 'error' => 'could not add series'];
        }

        if (!$sakaiSite) {
            $sakaiSite = $this->getUserHome($eid);
        }

        $creation = $this->uctEndpoint->addExternalToolToSite(
                        //token
                        $this->token,
                        //site id
                        $sakaiSite,
                        //Title of tool
                       'My Videos',
                        //lti launch url
                       getenv('OPENCAST_HOST') . getenv('OPENCAST_LTI_ENDPOINT'),
                       //parameters
                       "sid=$series[identifier];type=personal;tool=/ltitools/manage"
        );
        if ($creation === 'failure') {
            var_dump('could not create');
            return "could not create";
        }

        return $creation;
    }

    public function getUserSeries($email) {
        $user = $this->getUserByEmail($email);
        return $this->getOCSeries($user['homeSite']);
    }

    private function getOCSeries($siteId) {
        $username = getenv('OPENCAST_USER');
        $password = getenv('OPENCAST_PASSWORD');
        $url = getenv('OPENCAST_HOST') . getenv('OPENCAST_SERIES_ENDPOINT') . "?filter=textFilter:\\$siteId";
        $headers = ['Authorization: Basic ' . base64_encode("$username:$password"), 'Content-Type: application/x-www-form-urlencoded'];

        $series = json_decode($this->getRequest($url, $headers), true);
        if (!is_array($series) || !sizeof($series)) {
            return null;
        }

        return $series[0];
    }

    //$name = readable name, $display = name (email), $source_id = sakai eid, $context_id = user home site id
    private function createOCSeries($name, $display, $source_id, $context_id)
    {
        $checkSeries = $this->getOCSeries($context_id);
        if ($checkSeries) {
            return $checkSeries;
        }

        $username = getenv('OPENCAST_USER');
        $password = getenv('OPENCAST_PASSWORD');
        $remote_url = getenv('OPENCAST_HOST') . getenv('OPENCAST_SERIES_ENDPOINT');

        $series = array(
            array(
                'flavor' => 'dublincore/series',
                'title' => 'Opencast Series DublinCore',
                'fields' => array(
                    array('id' => 'title', 'value' => 'Personal Series ('. $name .')')
                    ,array('id' => 'subject', 'value' => 'Personal')
                    ,array('id' => 'description', 'value' => "Personal Series: $display\nSakai site: $context_id")
                    ,array('id' => 'language', 'value' => 'eng')
                    ,array('id' => 'rightsHolder', 'value' => 'University of Cape Town')
                    ,array('id' => 'license', 'value' => 'ALLRIGHTS')
                    ,array('id' => 'creator', 'value' => array($name))
                    ,array('id' => 'contributor', 'value' => array($name))
                    ,array('id' => 'publisher', 'value' => array($name))
                )
            ),
            array(
                'flavor' => 'ext/series',
                'title' => 'UCT Series Extended Metadata',
                'fields' => array(
                    array('id' => 'course', 'value' => '')
                    ,array('id' => 'creator-id', 'value' => $source_id)
                    ,array('id' => 'site-id', 'value' => $context_id)
                )
            )
        );

        // Create a stream
        $opts = array(
            'http'=>array(
                'method'=>'POST'
                ,'header' => array(
                    'Authorization: Basic ' . base64_encode("$username:$password"),
                    'Content-type: application/x-www-form-urlencoded'
            )
            ,'content' => http_build_query(
                array(
                    'metadata' => json_encode($series),
                    'acl' => '[{"action":"read","allow":true,"role":"ROLE_USER_' . $source_id . '"},{"action":"write","allow":true,"role":"ROLE_USER_' . $source_id . '"},{"action":"read","allow":true,"role":"ROLE_GROUP_CILT_OBS"},{"action":"write","allow":true,"role":"ROLE_GROUP_CILT_OBS"},{"action":"read","allow":true,"role":"ROLE_USER_PERSONALSERIESCREATOR"}]'
                )
            )
          )
        );

        $context = stream_context_create($opts);

        // Open the file using the HTTP headers set above
        $result = file_get_contents($remote_url, false, $context);

        if ($result != false) {
            $result = json_decode($result)->identifier;
        }

        return $result;
    }

    private function getRequest($url, $headers, $opts = [], $body = []) {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_URL ,  $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER ,  $headers);
        curl_setopt($curl, CURLOPT_FAILONERROR ,  false);
        curl_setopt($curl, CURLINFO_HEADER_OUT ,  true);
        curl_setopt($curl, CURLOPT_VERBOSE ,  true);

        $response = curl_exec($curl);

        $info = curl_getinfo($curl);

        // Check for errors
        if (curl_errno($curl)) {
            throw new \Exception(curl_error($curl));
        }

        // Check for errors
        if ($info['http_code'] >= 400) {
            $response = json_decode($response, true);

            $message = json_encode($info);
            //var_dump($response, $message);

            //throw new \Exception($message);
        }

        // Close request and clear some resources
        curl_close($curl);

        return $response;
    }

    public function __destruct() {
        $this->logout();
    }

    static function is_sequential($arr) {
      if (!is_array($arr)) return false;

      $keys = array_keys($arr);

      return array_keys($keys) === $keys;
    }

    static function xmlToArray($xml) {
      return json_decode(json_encode(simplexml_load_string($xml, "SimpleXMLElement", LIBXML_NOCDATA)), true);
    }
}

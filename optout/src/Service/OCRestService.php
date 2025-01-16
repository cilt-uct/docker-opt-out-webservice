<?php

namespace App\Service;

use Symfony\Component\Dotenv\Dotenv;
use App\Entity\OpencastSeries;

class OCRestService
{

    private $ocHost;
    private $ocUser;
    private $ocPass;

    public function __construct() {
        //Get environment variables
        $dotenv = new Dotenv();
        $dotenv->load('.env');

        //Get credentials
        $this->ocHost = getenv('OC_HOST');
        $this->ocUser = getenv('OC_USER');
        $this->ocPass = getenv('OC_PASS');
    }

    public function getSeriesMetadata($seriesId) {
        $url = $this->ocHost . "/admin-ng/series/$seriesId/metadata.json";

        $data = json_decode($this->getRequest($url), true);
        if (!is_array($data) || !sizeof($data)) {
            return [];
        }
        return $data;
    }

    public function getAllSeries($filter = '', $sort = '', $offset = 0, $limit = 10) {
        $url = $this->ocHost . "/admin-ng/series/series.json?offset=$offset&limit=$limit&sort=createdDateTime:DESC";

        $data = json_decode($this->getRequest($url), true);
        if (!is_array($data) || !sizeof($data)) {
            return [];
        } else {
            $data['results'] = array_map (function($s) {

                //https://media.uct.ac.za/api/series/006182a0-70c6-4f31-ae7d-0fcaddcc2ceb/metadata?type=ext%2Fseries
                    $ext = json_decode($this->getRequest($this->ocHost . "/api/series/".$s['id']."/metadata?type=ext%2Fseries"), true);
                    if (!is_array($ext) || !sizeof($ext)) {
                        return [];
                    }
                    $oc_series = new OpencastSeries($s['id']);
                    $s['hash'] = $oc_series->getHash();
                    $s['ext'] = $ext;
                    return $s;
                }, $data['results']);
        }
        return $data;
    }

    //https://media.uct.ac.za/admin-ng/event/events.json?filter=textFilter:2ef7d00a-7fc3-420a-9c4b-238c029d25e3,series:2ef7d00a-7fc3-420a-9c4b-238c029d25e3&limit=50&offset=0&sort=series_name:ASC
    public function getEventsForSeries($seriesId) {

        // $url = $this->ocHost . "/admin-ng/event/events.json?filter=series:$seriesId&sort=technical_start:ASC";
        // series.{format:xml|json}?id={id}&q={q}&episodes=false&sort={sort}&limit=20&offset=0&admin=false
        $url = $this->ocHost . "/search/series.json?id=$seriesId&episodes=true&limit=0&admin=true&sort=TITLE";

        $data = json_decode($this->getRequest($url), true);
        if (!is_array($data) || !sizeof($data)) {
            return [];
        }
        if ($data['search-results']) {
            return $data['search-results'];
        }
        return $data;
    }

    /**
     * Get event details for downloads - episode
     */
    public function getEventForPlayback($eventId) {

        $url = $this->ocHost . "/search/episode.json?id=596ff927-79bc-4a15-a39f-80ea8c7f16e0"; //"/search/episode.json?id=$eventId";

        $data = json_decode($this->getRequest($url), true);
        if (!is_array($data) || !sizeof($data)) {
            return [];
        }
        return $data;
    }

    private function getOCSeries($courseCode, $year) {
        $url = $this->ocHost . "/api/series/?filter=textFilter:$courseCode&limit=50&sort=created:DESC";

        $series = json_decode($this->getRequest($url), true);
        if (!is_array($series) || !sizeof($series)) {
            return [];
        }

        $series = array_filter($series, function($s) use ($year) {
                      return strpos($s['created'], $year) > -1;
                  });

        return $series;
    }

    public function hasOCSeries($courseCode, $year) {
        try {
            $checkSeries = $this->getOCSeries($courseCode, $year);
            return sizeof($checkSeries) > 0;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function isTimetabled($activityId) {
        try {
            $url = $this->ocHost . "/admin-ng/event/events.json?filter=textFilter:$activityId&limit=1";
            $activityEvent = json_decode($this->getRequest($url), true);
            if (isset($activityEvent['total']) && $activityEvent['total'] > 0) {
                return true;
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }

        return false;
    }

    public function isCourseHasEvents($courseCode = '', $year = 0) {
        if (!isset($courseCode) || empty($courseCode) || is_null($courseCode)) {
            return false;
        }

        $year = date('Y');

        try {
            $courseSeriesUrl = $this->ocHost . "/admin-ng/series/series.json?filter=textFilter:$courseCode&limit=1&sort=createdDateTime:DESC";
            $courseSeries = json_decode($this->getRequest($courseSeriesUrl), true);
            if (isset($courseSeries['total']) && $courseSeries['total'] > 0 && strpos($courseSeries['results'][0]['creation_date'], $year) > -1) {
                $seriesId = $courseSeries['results'][0]['id'];
                $seriesEventsUrl = $this->ocHost . "/admin-ng/event/events.json?filter=series:$seriesId&limit=1";
                $seriesEvents = json_decode($this->getRequest($seriesEventsUrl), true);
                if (isset($seriesEvents['total']) && $seriesEvents['total'] > 0) {
                    return true;
                }
            }
        } catch (\Exception $e) {
        }

        return false;
    }

    // OCRestService->updateRetention('0cda8b2e-50ef-4da4-82dc-fc53e0f74d54', 'long', '2027-09-16T00:00:00.000Z', '01450343')
    public function updateRetention($series_id, $new_retention, $expiry_date, $username) {
        try {
            $now = new \DateTime();
            $url = $this->ocHost . "/api/series/$series_id";
            $body = '[{ "flavor": "ext/series", "title": "UCT Series Extended Metadata", "fields": [ { "id": "retention-cycle", "type": "text", "value": "'. $new_retention .'" }';

            $notes = '';
            $prev_ret = '';
            $tmp = json_decode($this->getRequest($this->ocHost . "/api/series/".$series_id."/metadata?type=ext%2Fseries"), true);
            if (is_array($tmp)) {
                $ext = [];
                foreach($tmp as $field) {
                    $ext[ str_replace("-","_",$field['id'])] = $field['value'];
                }

                if (count($ext['series_notes']) > 0) {
                    $notes = $ext['series_notes'][0];
                }
                $prev_ret = $ext['retention_cycle'] == '' ? 'NA' : $ext['retention_cycle'];
            }

            if ($expiry_date != 'forever') {
                $body .= ', { "id": "series-expiry-date", "type": "date","value": "'. $expiry_date .'" }';
            } else {
                $body .= ', { "id": "series-expiry-date", "type": "date","value": "" }';
            }

            $body .= ', { "id": "series-notes", "type": "mixed_text",'
                    .'"value": ["'. ($notes != '' ? $notes.'|' : '') .'Retention changed from '. $prev_ret .' to '. $new_retention .' by '.$username.' on '. $now->format("Y-m-d H:i") .'"] }';

            $body .= '] }]';
            $result = $this->putRequest($url, array('metadata' => $body));

            $result['body'] = $body;
            $result['success'] = ($result['code'] == '200');

            return $result;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }

        return ['success' => false];
    }

    public function updateNotificationList($series_id, $notification_list) {
        try {
            $url = $this->ocHost . "/api/series/$series_id";
            $body = '[{ "flavor": "ext/series", "title": "UCT Series Extended Metadata", "fields": [ { "id": "notification-list", "type": "text", "value": ["'. $notification_list .'"] }] }]';
            $result = $this->putRequest($url, array('metadata' => $body));

            $result['success'] = ($result['code'] == '200');

            return $result;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }

        return ['success' => false];
    }

    private function getRequest($url) {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_URL , $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER , ['X-Requested-Auth: Digest', "X-Opencast-Matterhorn-Authorization: true"]);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($curl, CURLOPT_USERPWD, $this->ocUser . ':' . $this->ocPass);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_VERBOSE, true);

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
            //throw new \Exception($response);
        }

        // Close request and clear some resources
        curl_close($curl);

        return $response;
    }

    private function putRequest($url, $body = []) {

        $curl = curl_init($url);

        // Use the CURLOPT_PUT option to tell cURL that
        // this is a PUT request.
        //curl_setopt($curl, CURLOPT_PUT, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");

        // We want the result / output returned.
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_URL , $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER , ['X-Requested-Auth: Digest', "X-Opencast-Matterhorn-Authorization: true"]);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($curl, CURLOPT_USERPWD, $this->ocUser . ':' . $this->ocPass);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_VERBOSE, true);

        //, "Content-Type: application/x-www-form-urlencoded; charset=UTF-8"

        // Our body
        curl_setopt($curl, CURLOPT_POSTFIELDS,http_build_query($body));

        // // The path of the file that we want to PUT
        // $filepath = 'example_file.txt';

        // //Open the file using fopen.
        // $fileHandle = fopen($filepath, 'r');

        // //Pass the file handle resorce to CURLOPT_INFILE
        // curl_setopt($ch, CURLOPT_INFILE, $fileHandle);

        // //Set the CURLOPT_INFILESIZE option.
        // curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filepath));

        //Execute the request.
        $response = curl_exec($curl);
        $info = curl_getinfo($curl);

        // Check for errors
        if (curl_errno($curl)) {
            throw new \Exception(curl_error($curl));
        }

        // Close request and clear some resources
        curl_close($curl);

        return ['code' => $info['http_code'], 'response' => $response];
    }

}

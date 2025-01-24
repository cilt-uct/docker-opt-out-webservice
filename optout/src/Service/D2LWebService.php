<?php

namespace App\Service;

use Symfony\Component\Dotenv\Dotenv;

class D2LWebService
{

    private $host;
    private $user;
    private $pass;

    public function __construct()
    {
        // Get environment variables
        $dotenv = new Dotenv();
        $dotenv->load('.env');

        // Get credentials
        $this->host = getenv('D2L_HOST');
        $this->user = getenv('D2L_USER');
        $this->pass = getenv('D2L_PASS');
    }

    public function search($searchstr) {
        $data = json_decode($this->makeCurlRequest("/api/db/user/$searchstr"), true);
        if (!is_array($data) || !sizeof($data)) {
            return [];
        }
        return $data;
    }

    public function getConvenor($searchstr) {
        $data = $this->search($searchstr);
        $default = [
            'eid' => NULL,
            'name' => NULL,
            'email' => NULL,
            'title' => NULL
        ];

        if ($data['status'] == 'success') {
            $users = $data['data'];
            $selectedUser = null;

            // Search for the user with "src" as "idv"
            foreach ($users as $user) {
                if ($user['src'] === 'idv') {
                    $selectedUser = $user;
                    break; // Prefer "idv" and stop searching
                }
            }

            // If no "idv" source found, search for "spml"
            if (!$selectedUser) {
                foreach ($users as $user) {
                    if ($user['src'] === 'spml') {
                        $selectedUser = $user;
                        break;
                    }
                }
            }

            // If a user is found, extract the required fields
            if ($selectedUser) {
                return [
                    'name' => ($selectedUser['preferred_firstname'] == NULL ? $selectedUser['firstname'] : $selectedUser['preferred_firstname']) .' '. $selectedUser['lastname'],
                    'email' => ($selectedUser['email'] == NULL ? $selectedUser['alt_email'] : $selectedUser['email']),
                    'eid' => $selectedUser['eid'],
                    'title' => $selectedUser['title']
                ];
            }

            // Return null if no matching user found
            return $default;
        }
        return $default;
    }

    public function list($part) {
        if (strlen($part) <= 1) {
            return [];
        }
        $data = json_decode($this->makeCurlRequest("/api/db/staff/list2/$part"), true);
        if (!is_array($data) || !sizeof($data)) {
            return [];
        }
        return $data;
    }

    public function hasSite($code, $term) {
        try {
            $data = json_decode($this->makeCurlRequest("/api/course/link/". $code ."_". $term), true);

            // Check if the status is "success"
            if ($data['status'] !== 'success') {
                return 0; // Return 0 if the status is not "success"
            }

            // Filter the data entries where "is_exists" is 1
            $filteredData = array_filter($data['data'], function ($entry) {
                return $entry['is_exists'] === 1;
            });

            return count($filteredData) > 0;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    function makeCurlRequest($url, $method = 'GET', $postData = null) {
        // Initialize cURL
        $ch = curl_init();

        // Set cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->host . $url,
            CURLOPT_RETURNTRANSFER => true, // Return the response as a string
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC, // Use Basic Authentication
            CURLOPT_USERPWD => $this->user.":".$this->pass, // Credentials for authentication
            CURLOPT_CUSTOMREQUEST => $method, // HTTP method (GET, POST, etc.)
        ]);

        // If the method is POST and data is provided, set the POST fields
        if ($method === 'POST' && !empty($postData)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }

        // Execute the cURL request
        $response = curl_exec($ch);

        // Check for errors
        if (curl_errno($ch)) {
            echo 'cURL Error: ' . curl_error($ch);
        }

        // Close the cURL session
        curl_close($ch);

        // Return the response
        return $response;
    }

}

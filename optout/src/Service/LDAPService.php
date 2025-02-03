<?php

namespace App\Service;

use Symfony\Component\Dotenv\Dotenv;
use Psr\Log\LoggerInterface;

class LDAPService
{
    private $host;
    private $port;
    private $user;
    private $password;
    private $baseDn;
    private $filter;
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $dotenv = new DotEnv();
        $dotenv->load('.env');

        $this->host = getenv('LDAP_HOST');
        $this->port = getenv('LDAP_PORT');
        $this->user = getenv('LDAP_USER');
        $this->password = getenv('LDAP_PASSWORD');
        $this->baseDn = getenv('LDAP_BASE_DN');
        $this->filter = getenv('LDAP_FILTER');

        $this->logger = $logger;
    }

    public function authenticate(string $username, string $password): bool
    {
        $this->logger->info("Attempting to authenticate user '{$username}'.");

        // Try to connect to LDAP server
        $connection = ldap_connect($this->host, $this->port);

        if (!$connection) {
            $this->logger->error("Unable to connect to LDAP server: {$this->host}:{$this->port}");
            throw new \RuntimeException("Unable to connect to LDAP server.");
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);

        // Check if we can bind with the service account
        if (!ldap_bind($connection, $this->user, $this->password)) {
            $this->logger->error("Unable to bind to LDAP server with user: {$this->user}");
            ldap_unbind($connection);
            throw new \RuntimeException("Unable to bind to LDAP server.");
        }

        // Search for the user DN
        $search = ldap_search($connection, $this->baseDn, sprintf($this->filter, ldap_escape($username, '', LDAP_ESCAPE_FILTER)));
        if (!$search) {
            $this->logger->error("LDAP search failed for user '{$username}'.");
            ldap_unbind($connection);
            return false;
        }

        $entries = ldap_get_entries($connection, $search);
        if ($entries['count'] === 0) {
            $this->logger->warning("User '{$username}' not found in LDAP.");
            ldap_unbind($connection);
            return false; // User not found
        }

        $userDn = $entries[0]['dn'];

        // Authenticate the user with their password
        if (@ldap_bind($connection, $userDn, $password)) {
            $this->logger->info("User '{$username}' authenticated successfully.");
            ldap_unbind($connection);
            return true; // Authentication successful
        }

        $this->logger->warning("Authentication failed for user '{$username}'.");
        ldap_unbind($connection);
        return false; // Authentication failed
    }

    public function findUserByCN(string $cn): ?array
    {
        $this->logger->info("Searching for user with CN '{$cn}'.");

        // Try to connect to LDAP server
        $connection = ldap_connect($this->host, $this->port);

        if (!$connection) {
            $this->logger->error("Unable to connect to LDAP server: {$this->host}:{$this->port}");
            throw new \RuntimeException("Unable to connect to LDAP server.");
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);

        // Check if we can bind with the service account
        if (!ldap_bind($connection, $this->user, $this->password)) {
            $this->logger->error("Unable to bind to LDAP server with user: {$this->user}");
            ldap_unbind($connection);
            throw new \RuntimeException("Unable to bind to LDAP server.");
        }

        // Search for the user DN
        $search = ldap_search($connection, $this->baseDn, sprintf($this->filter, ldap_escape($cn, '', LDAP_ESCAPE_FILTER)));
        if (!$search) {
            $this->logger->error("LDAP search failed for user '{$cn}'.");
            ldap_unbind($connection);
            return false;
        }

        $entries = ldap_get_entries($connection, $search);
        if ($entries['count'] === 0) {
            $this->logger->warning("No user found with CN '{$cn}'.");
            ldap_unbind($connection);
            return null;
        }

        $filteredResults = [];
        if (count($entries) > 0) {
            // $filteredKeys = array_values(array_filter($entries[0], 'is_string'));
            $filteredKeys = ['dn', 'cn', 'sn', 'title', 'givenname', 'initials', 'displayname', 'department', 'mail'];

            // Loop through filteredKeys and add existing keys to filteredResults
            for ($i = 0; $i < count($filteredKeys); $i++) {
                $key = $filteredKeys[$i];
                if (isset($entries[0][$key])) {
                    // If value is an array and contains key "0", extract that value
                    if (is_array($entries[0][$key]) && isset($entries[0][$key]["0"])) {
                        $filteredResults[$key] = $entries[0][$key]["0"];
                    } else {
                        $filteredResults[$key] = $entries[0][$key];
                    }
                }
            }
        }

        $this->logger->info("User with CN '{$cn}' found successfully.");
        // $this->logger->info(json_encode(array_keys($entries[0])));

        // Return user details as an associative array
        ldap_unbind($connection);
        return $filteredResults;
    }

}
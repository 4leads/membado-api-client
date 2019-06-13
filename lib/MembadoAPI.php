<?php
/**
 * This libary allows you to quickly and easily perform REST actions on the membado backend using PHP.
 *
 * @author    Bertram Buchardt <support@4leads.de>
 * @copyright 2019 4leads GmbH
 * @license   https://opensource.org/licenses/MIT The MIT License
 */

namespace Membado;


use stdClass;

/**
 * Interface to the Membado Web API
 */
class MembadoAPI
{
    const VERSION = '0.9.2';

    //Client properties
    /**
     * @var string
     */
    protected $host;

    /**
     * @var array
     */
    protected $headers;

    /**
     * @var string
     */
    protected $version;

    /**
     * @var array
     */
    protected $path;

    /**
     * @var array
     */
    protected $curlOptions;
    /**
     * @var string
     */
    protected $apiKey;
    protected $lastResponse;
    //END Client properties

    /**
     * Setup the HTTP Client
     *
     * @param string $apiKey your membado API Key.
     * @param array $options an array of options, currently only "host" and "curl" are implemented.
     */
    public function __construct($apiKey, $host, $options = [])
    {
        $headers = [
            'User-Agent: four-leads-api/' . self::VERSION . ';php',
            'Accept: application/json',
        ];
        $this->apiKey = $apiKey;
        $curlOptions = isset($options['curl']) ? $options['curl'] : null;
        $this->setupClient($host, $headers, '', null, $curlOptions);
    }

    /**
     * Initialize the client
     *
     * @param string $host the base url (e.g. https://api.membado.net)
     * @param array $headers global request headers
     * @param string $version api version (configurable) - this is specific to the membado API
     * @param array $path holds the segments of the url path
     * @param array $curlOptions extra options to set during curl initialization
     */
    protected function setupClient($host, $headers = null, $version = null, $path = null, $curlOptions = null)
    {
        $this->host = $host;
        $this->headers = $headers ?: [];
        $this->version = $version;
        $this->path = $path ?: [];
        $this->curlOptions = $curlOptions ?: [];
    }

    /**
     * Test the API-KEY
     * @return bool
     */
    public function auth()
    {
        $path = '/auth';
        $url = $this->buildUrl($path);
        $response = $this->makeRequest($url);
        return $response->responseBody->success === true;
    }

    /**
     * Build the final URL to be passed
     * @param string $path $the relative Path inside the api
     * @param array $queryParams an array of all the query parameters
     * @return string
     */
    private function buildUrl($path, $queryParams = null)
    {
        if (isset($queryParams) && is_array($queryParams) && count($queryParams)) {
            $path .= '?' . http_build_query($queryParams);
        }
        return sprintf('%s%s%s', $this->host, $this->version ?: '', $path);
    }

    /**
     * Make the API call and return the response.
     * This is separated into it's own function, so we can mock it easily for testing.
     *
     * @param string $url the final url to call
     * @param array $body request body
     * @param string $method the HTTP verb
     * @param array $headers any additional request headers
     *
     * @return stdClass object
     */
    public function makeRequest($url, $body = null, $method = 'POST', $headers = null)
    {
        $channel = curl_init($url);

        $options = $this->createCurlOptions($method, $body, $headers);

        curl_setopt_array($channel, $options);
        $content = curl_exec($channel);

        $this->lastResponse = $response = $this->parseResponse($channel, $content);

        curl_close($channel);

        if (strlen($response->responseBody)) {
            $response->responseBody = json_decode($response->responseBody);
        }

        return $response;
    }

    /**
     * Creates curl options for a request
     * this function does not mutate any private variables
     *
     * @param string $method
     * @param array $params
     * @param array $headers
     *
     * @return array
     */
    private function createCurlOptions($method, $params = null, $headers = null)
    {
        $options = [
                CURLOPT_HEADER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_FAILONERROR => false,
                CURLOPT_USERAGENT => 'membado php-cli-client,v' . self::VERSION,
            ] + $this->curlOptions
            + [
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_RETURNTRANSFER => true,
            ];

        if (isset($headers)) {
            $headers = array_merge($this->headers, $headers);
        } else {
            $headers = $this->headers;
        }

        if (is_array($params)) {
            $params['apikey'] = $this->apiKey;
            $options[CURLOPT_POSTFIELDS] = http_build_query($params);
        } else {
            $options[CURLOPT_POSTFIELDS] = http_build_query(['apikey' => $this->apiKey]);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        return $options;
    }

    /**
     * Prepare response object.
     *
     * @param resource $channel the curl resource
     * @param string $content
     *
     * @return stdClass object
     */
    private function parseResponse($channel, $content)
    {
        $response = new stdClass();
        $response->headerSize = curl_getinfo($channel, CURLINFO_HEADER_SIZE);
        $response->statusCode = curl_getinfo($channel, CURLINFO_HTTP_CODE);
        $response->responseBody = substr($content, $response->headerSize);
        $response->responseHeaders = substr($content, 0, $response->headerSize);
        $response->responseHeaders = explode("\n", $response->responseHeaders);
        $response->responseHeaders = array_map('trim', $response->responseHeaders);

        return $response;
    }

    /**
     * Get the list of tags in the Account
     * @return array|bool
     */
    public function tags()
    {
        $path = '/tags';
        $url = $this->buildUrl($path);
        $response = $this->makeRequest($url);
        if ($response->responseBody instanceof stdClass) {
            $body = $response->responseBody;
            if (!isset($body->success) || !$body->success) {
                return false;
            }
            //convert to array not object
            $tags = [];
            foreach ($body->result->tags as $id => $tag) {
                $tags[$id] = $tag;
            }
            return $tags;
        }
        return false;
    }

    /**
     * Get the array of fields id => name
     * @param bool $filterDefault Filter default system fields and only show customfields
     * @return array|bool
     */
    public function fields($filterDefault = true)
    {
        $path = '/fields';
        $url = $this->buildUrl($path);
        $response = $this->makeRequest($url);
        if ($response->responseBody instanceof stdClass) {
            $body = $response->responseBody;
            if (!isset($body->success) || !$body->success) {
                return false;
            }
            //convert to array not object
            $fields = [];
            foreach ($body->result->fields as $id => $field) {
                if ($filterDefault && strpos($id, 'customfield_') !== 0) {
                    continue;
                }
                $fields[$id] = $field;
            }
            return $fields;
        }
        return false;
    }

    /**
     * Get the contact details of a contact as object
     * @param int|string $idOrEmail The membado contact id or email of the contact
     * @return stdClass|bool
     */
    public function contact($idOrEmail)
    {
        $path = '/contact';
        if (is_numeric($idOrEmail)) {
            $params = [Contact::CONTACT_ID => $idOrEmail];
        } else {
            $params = [Contact::CONTACT_MAIL => $idOrEmail];
        }

        $url = $this->buildUrl($path);
        $response = $this->makeRequest($url, $params);
        if ($response->responseBody instanceof stdClass) {
            $body = $response->responseBody;
            if (!isset($body->success) || !$body->success) {
                return false;
            }
            return $body->result;
        }
        return false;
    }

    /**
     * Get n array of contact tags as id => name
     * @param int|string $idOrEmail The membado contact id or email of the contact
     * @return array|bool
     */
    public function contactTags($idOrEmail)
    {
        $path = '/contact/tags';
        if (is_numeric($idOrEmail)) {
            $params = [Contact::CONTACT_ID => $idOrEmail];
        } else {
            $params = [Contact::CONTACT_MAIL => $idOrEmail];
        }

        $url = $this->buildUrl($path);
        $response = $this->makeRequest($url, $params);
        if ($response->responseBody instanceof stdClass) {
            $body = $response->responseBody;
            if (!isset($body->success) || !$body->success) {
                return false;
            }
            //convert to array not object
            $tags = [];
            foreach ($body->result->tags as $id => $tag) {
                $tags[$id] = $tag;
            }
            return $tags;
        }
        return false;
    }

    /**
     * Create or Update a contact
     * @param $idOrEmail
     * @param array $fields
     * @param array $addTags
     * @param array $removeTags
     * @param int|null $optinId
     * @return bool
     */
    public function contactCreateUpdate($idOrEmail, array $fields = [], array $addTags = [], array $removeTags = [], int $optinId = null)
    {
        $path = '/contact/create_or_update';
        if (is_numeric($idOrEmail)) {
            $params = [Contact::CONTACT_ID => $idOrEmail];
        } else {
            $params = [Contact::CONTACT_MAIL => $idOrEmail];
        }
        //add fields as params
        foreach ($fields as $key => $value) {
            $params[$key] = $value;
        }
        if (count($addTags)) {
            $params['tags_add'] = implode(',', $addTags);
        }
        if (count($removeTags)) {
            $params['tags_remove'] = implode(',', $removeTags);
        }
        if (is_numeric($optinId)) {
            $params['optin_id'] = $optinId;
        }
        $url = $this->buildUrl($path);
        $response = $this->makeRequest($url, $params);
        if ($response->responseBody instanceof stdClass) {
            $body = $response->responseBody;
            return isset($body->success) && $body->success;
        }
        return false;
    }

    /**
     *  Get n array of contact tags as id => name
     * @param $idOrEmail
     * @param array $fieldIds
     * @return array|bool
     */
    public function contactFields($idOrEmail, array $fieldIds)
    {
        $path = '/contact/fields/get';
        if (is_numeric($idOrEmail)) {
            $params = [Contact::CONTACT_ID => $idOrEmail];
        } else {
            $params = [Contact::CONTACT_MAIL => $idOrEmail];
        }

        $fieldString = implode(',', $fieldIds);
        $params['tags'] = $fieldString;
        $url = $this->buildUrl($path);
        $response = $this->makeRequest($url, $params);
        if ($response->responseBody instanceof stdClass) {
            $body = $response->responseBody;
            if (!isset($body->success) || !$body->success) {
                return false;
            }
            //convert to array not object
            $fields = [];
            foreach ($body->result as $id => $field) {
                $fields[$id] = $field;
            }
            return $fields;
        }
        return false;
    }

    /**
     * Set the optin status of a contact
     * @param $idOrEmail
     * @param string $optinStatus
     * @return bool
     */
    public function contactSetOptin($idOrEmail, string $optinStatus)
    {
        $path = '/contact/set-optin-status';
        if (is_numeric($idOrEmail)) {
            $params = [Contact::CONTACT_ID => $idOrEmail];
        } else {
            $params = [Contact::CONTACT_MAIL => $idOrEmail];
        }
        if (!in_array($optinStatus, [Contact::OPTIN_NULL, Contact::OPTIN_OPTOUT, Contact::OPTIN_SINGLE])) {
            return false;
        }
        $params['optin_status'] = $optinStatus;
        $url = $this->buildUrl($path);
        $response = $this->makeRequest($url, $params);
        if ($response->responseBody instanceof stdClass) {
            $body = $response->responseBody;
            return isset($body->success) && $body->success;
        }
        return false;
    }

    /**
     * Start the optin for the contact
     * @param $idOrEmail
     * @param int $optinId
     * @return bool
     */
    public function contactStartOptin($idOrEmail, int $optinId)
    {
        $path = '/contact/optin/start';
        if (is_numeric($idOrEmail)) {
            $params = [Contact::CONTACT_ID => $idOrEmail];
        } else {
            $params = [Contact::CONTACT_MAIL => $idOrEmail];
        }

        $params['optin_id'] = $optinId;
        $url = $this->buildUrl($path);
        $response = $this->makeRequest($url, $params);
        if ($response->responseBody instanceof stdClass) {
            $body = $response->responseBody;
            return isset($body->success) && $body->success;
        }
        return false;
    }


    /**
     * Add given Tags to the contact
     * @param int|string $idOrEmail The membado contact id or email of the contact
     * @param array $tagIds array of ids which should be added to the contact
     * @return bool
     */
    public function contactTagsAdd($idOrEmail, array $tagIds)
    {
        $path = '/contact/tags/add';
        if (is_numeric($idOrEmail)) {
            $params = [Contact::CONTACT_ID => $idOrEmail];
        } else {
            $params = [Contact::CONTACT_MAIL => $idOrEmail];
        }
        //convert tagIds
        $tagString = implode(',', $tagIds);
        $params['tags'] = $tagString;
        $url = $this->buildUrl($path);
        $response = $this->makeRequest($url, $params);
        if ($response->responseBody instanceof stdClass) {
            $body = $response->responseBody;
            return isset($body->success) && $body->success;
        }
        return false;
    }


    /**
     * Remove given Tags to the contact
     * @param int|string $idOrEmail The membado contact id or email of the contact
     * @param array $tagIds array of ids which should be remove from the contact
     * @return bool
     */
    public function contactTagsRemove($idOrEmail, array $tagIds)
    {
        $path = '/contact/tags/remove';
        if (is_numeric($idOrEmail)) {
            $params = [Contact::CONTACT_ID => $idOrEmail];
        } else {
            $params = [Contact::CONTACT_MAIL => $idOrEmail];
        }
        //convert tagIds
        $tagString = implode(',', $tagIds);
        $params['tags'] = $tagString;
        $url = $this->buildUrl($path);
        $response = $this->makeRequest($url, $params);
        if ($response->responseBody instanceof stdClass) {
            $body = $response->responseBody;
            return isset($body->success) && $body->success;
        }
        return false;
    }

    /**
     * Get the array of optins id => name
     * @return array|bool
     */
    public function optins()
    {
        $path = '/optins';
        $url = $this->buildUrl($path);
        $response = $this->makeRequest($url);
        if ($response->responseBody instanceof stdClass) {
            $body = $response->responseBody;
            if (!isset($body->success) || !$body->success) {
                return false;
            }
            //convert to array not object
            $optins = [];
            foreach ($body->result->optins as $id => $optin) {
                $optins[$id] = $optin;
            }
            return $optins;
        }
        return false;
    }


    /**
     * @param string $apiKey
     */
    public function setApiKey(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }


    /**
     * @return stdClass|null
     */
    public function getLastResponse()
    {
        return $this->lastResponse;
    }


    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @return string|null
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @return array
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return array
     */
    public function getCurlOptions()
    {
        return $this->curlOptions;
    }

    /**
     * Set extra options to set during curl initialization
     *
     * @param array $options
     * @return MembadoAPI
     */
    public function setCurlOptions(array $options)
    {
        $this->curlOptions = $options;

        return $this;
    }
}
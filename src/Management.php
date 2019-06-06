<?php

namespace Webstarters\Platform;

use function array_merge;
use Webstarters\Exceptions\ManagementException;

class Management
{
    private $protocol;
    private $server;
    private $version;

    private $key;
    private $secret;

    /**
     * Create a new Management Instance.
     */
    public function __construct($key = null, $secret = null)
    {
        $this->key = $key ?? config('jw-platform.management.key', null);
        $this->secret = $secret ?? config('jw-platform.management.secret', null);

        $this->protocol = config('jw-platform.management.protocol', 'https');
        $this->server = config('jw-platform.management.server', 'api.jwplatform.com');
        $this->version = config('jw-platform.management.version', 'v1');
    }


    // RFC 3986 complient rawurlencode()
    // Only required for phpversion() <= 5.2.7RC1
    // See http://www.php.net/manual/en/function.rawurlencode.php#86506
    private function encode($input) {
        if (is_array($input)) {
            return array_map(['encode'], $input);
        }

        if (is_scalar($input)) {
            return str_replace('+', ' ', str_replace('%7E', '~', rawurlencode($input)));
        }

        return '';
    }

    private function signature($data) {

        $signatureBaseString = '';

        ksort($data);

        // Construct Signature Base String
        foreach ($data as $key => $value) {
            if ($signatureBaseString != '') {
                $signatureBaseString .= '&';
            }

            $signatureBaseString .= $this->encode($key) . "=" . $this->encode($value);
        }

        // Add shared secret to the Signature Base String and generate the signature
        return sha1($signatureBaseString . $this->secret);
    }

    private function buildQueryParameters($data) {

        $data['api_nonce'] = str_pad(mt_rand(0, 99999999), 8, STR_PAD_LEFT);
        $data['api_timestamp'] = time();
        $data['api_key'] = $this->key;
        $data['api_format'] = 'json';

        $data['api_signature'] = $this->signature($data);

        return http_build_query($data, '', '&');
    }

    private function formatUrl($url, $data = []) {
        return sprintf(
            '%s://%s/%s/%s?%s',
            $this->protocol,
            $this->server,
            $this->version,
            ltrim($url, '/'),
            $this->buildQueryParameters($data)
        );
    }

    public function call($url, $data = [], $method = 'GET') {

        $formattedUrl = $this->formatUrl($url, $data);

        $client = new \GuzzleHttp\Client();

        $response = $client->request($method, $formattedUrl, [
            'headers' => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
        ]);

        $body = $response->getBody();

        $decodedResponse = json_decode($body, true);

        if ($decodedResponse['status'] == 'error') {
            throw ManagementException::error($decodedResponse);
        }

        return $decodedResponse;
    }

    public function get($url, $data = []) {
        return $this->call($url, $data, 'GET');
    }

    public function post($url, $data = []) {
        return $this->call($url, $data, 'POST');
    }

    public function create($video_source_url, $video_metadata = [], $tags = '') {
        // do whatever it takes to attach a file from local temporary storage and send it to JWplayer

        $video_data = [
            'sourcetype'    => 'file', // url or file,
            'download_url'    => $video_source_url,
            'tags' => $tags
        ];

        $video_data = array_merge($video_data, $video_metadata);

        return $this->call('/videos/create', $video_data, 'POST');
    }

    public function update($video_token, $video_metadata = [], $tags = '') {

        $update_data = [
            'video_key' => $video_token,
            'title' => !empty($video_metadata['title']) ? $video_metadata['title'] : '',
            'description' => !empty($video_metadata['description']) ? $video_metadata['description'] : '',
            'author' => !empty($video_metadata['author']) ? $video_metadata['author'] : '',
            'tags' => $tags
        ];

        return $this->call('/videos/update/', $update_data, 'POST');
    }


}

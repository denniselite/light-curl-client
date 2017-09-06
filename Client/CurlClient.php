<?php
/**
 * Created by PhpStorm.
 * User: Denniselite
 * Date: 26/07/2017
 * Time: 23:57
 */

namespace Client;

class CurlClient
{

    const DEFAULT_ENCODING = 'UTF-8';
    const
        BUILD_QUERY = 'q',
        BUILD_JSON = 'j'
    ;

    /** @var string */
    protected $host;

    /** @var \resource */
    protected $curl;

    /** @var string */
    protected $method;

    /** @var  */
    protected $logger;

    /**
     * @var string
     */
    public $api;

    /**
     * @var bool
     */
    public $responseLoggingDisabled = false;

    /**
     * @var string
     */
    public $lastRequest;

    /**
     * @var string
     */
    public $lastRequestHeaders;

    /**
     * @var string
     */
    public $lastResponse;

    /**
     * @var string
     */
    public $lastResponseHeaders;

    /**
     * @var array
     */
    private $optHeaders = array();

    /**
     * @var string
     */
    private $encoding = self::DEFAULT_ENCODING;

    /**
     * @param string $host
     * @param array $options
     */
    public function __construct($host, $options = array())
    {
        $this->logger = \Yii::getLogger();
        $this->curl = curl_init();
        $this->host = $host;

        if (!is_array($options)) {
            $options = array();
        }
        foreach ($options as $key => $value) {
            if (is_null($value)) {
                unset($options[$key]);
            }
        }
        $options += [
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FAILONERROR => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_VERBOSE => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLINFO_HEADER_OUT => true,
        ];

        curl_setopt_array($this->curl, $options);
    }

    /**
     * Добавляет к исходящим заголовкам новый
     *
     * @param string $header
     * @return $this
     */
    public function addHeader($header)
    {
        $this->optHeaders[] = $header;
        return $this;
    }

    /**
     * @param string $url
     * @return $this
     */
    public function setUrl($url)
    {
        $options = array(CURLOPT_URL => $url);

        if (0 === strpos($url, 'https')) {
            $options += array(
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
            );
        }

        curl_setopt_array($this->curl, $options);
        return $this;
    }

    /**
     * @param $method
     * @return $this
     */
    public function setMethod($method)
    {
        $this->method = strtolower($method);
        $options = [
            CURLOPT_POST => ($this->method == 'post'),
        ];
        if (!in_array($method, ['get', 'post'])) {
            $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
        }
        curl_setopt_array($this->curl, $options);
        return $this;
    }

    /**
     * @param string $encoding
     * @return $this
     */
    public function setEncoding($encoding)
    {
        $this->encoding = $encoding;
        return $this;
    }

    /**
     * @param string $uri
     * @param array $data
     * @return string
     * @throws HttpException
     * @throws \Exception
     */
    public function get($uri, array $data = array())
    {
        return $this->exec($uri, 'get', $data, true);
    }

    /**
     * @param string $uri
     * @param array $data
     * @return CurlClient
     * @throws HttpException
     * @throws \Exception
     */
    public function post($uri, array $data = array())
    {
        return $this->exec($uri, 'post', $data, true, self::BUILD_JSON);
    }

    /**
     * @param $uri
     * @param array $data
     * @return CurlClient
     * @throws HttpException
     * @throws \Exception
     */
    public function put($uri, array $data)
    {
        return $this->exec($uri, 'put', $data, true, self::BUILD_JSON);
    }

    /**
     * @param $uri
     * @param array $data
     * @return CurlClient
     * @throws HttpException
     * @throws \Exception
     */
    public function options($uri, array $data)
    {
        return $this->exec($uri, 'options', $data, true, self::BUILD_JSON);
    }

    /**
     * @return string
     */
    public function text()
    {
        return (string) $this->lastResponse;
    }

    /**
     * @return array
     */
    public function json()
    {
        return (array) json_decode($this->lastResponse, true);
    }

    /**
     * @param string $uri
     * @param string $method
     * @param array $data
     * @param bool $build
     * @param string $buildMethod
     * @return CurlClient
     * @throws HttpException
     * @throws \Exception
     */
    public function exec($uri, $method, array $data = array(), $build = true, $buildMethod = self::BUILD_QUERY)
    {
        $url = $this->host . $uri;
        $this->setUrl($url);
        $this->setMethod($method);

        $this->lastRequest = null;
        $this->lastRequestHeaders = null;
        $requestBodyForLog = null;
        if (in_array($this->method, ['get', 'options'])) {
            if ($build) {
                curl_setopt($this->curl, CURLOPT_URL, $url . '?' . http_build_query($data));
            }
        } else {
            if (!$build) {
                $requestBodyForLog = $this->lastRequest = array_shift($data);
            } else {
                $this->lastRequest = $buildMethod === self::BUILD_JSON ? json_encode($data) : http_build_query($data);
                $requestBodyForLog = $this->lastRequest;
            }
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $this->lastRequest);
        }

        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $this->optHeaders);

        $created = microtime(true);
        $response = curl_exec($this->curl);

        $headers = trim(curl_getinfo($this->curl, CURLINFO_HEADER_OUT));
        if (false === $this->lastResponse) {
            if (!$headers) {
                $headers = 'url: ' . $url;
            }

            $this->logger->log(
                "CURL-Error:\n\n" . $headers . "\n\n" . curl_error($this->curl) . ':' . curl_errno($this->curl),
                CLogger::LEVEL_ERROR,
                $this->api
            );
        } else {
            $this->logger->log(
                "CURL-Request:\n\n{$headers}\n\n{$requestBodyForLog}\n",
                CLogger::LEVEL_INFO,
                $this->api
            );
            $this->logger->flush();

            if (!$this->responseLoggingDisabled) {
                $responseDebug = $response;
                if (self::DEFAULT_ENCODING !== $this->encoding) {
                    try {
                        $responseDebug = iconv($this->encoding, self::DEFAULT_ENCODING, $responseDebug);
                    } catch (\Exception $e) {
                        $this->logger->log($e, CLogger::LEVEL_WARNING, $this->api);
                    }
                }

                $interval = number_format(microtime(true) - $created, 4, '.', '');
                $this->logger->log(
                    "CURL-Response [{$interval}]:\n\n" . $responseDebug,
                    CLogger::LEVEL_INFO,
                    $this->api
                );
            }
        }

        $this->lastRequestHeaders = $headers;
        $this->lastResponseHeaders = substr($response, 0, curl_getinfo($this->curl, CURLINFO_HEADER_SIZE));
        $this->lastResponse = substr($response, curl_getinfo($this->curl, CURLINFO_HEADER_SIZE));

        if (CURLE_OK != curl_errno($this->curl)) {
            throw new \Exception(
                curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL) . ' : ' . curl_error($this->curl),
                curl_errno($this->curl)
            );
        }

        $code = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

        if (400 <= $code) {
            throw new CHttpException($code, 'HTTP error occurs: ' . $this->lastResponse);
        }

        return $this;
    }

    /**
     * @param int $name
     * @param mixed $value
     * @return bool
     */
    public function setOpt($name, $value)
    {
        if ($name === CURLOPT_HTTPHEADER) {
            $this->optHeaders = array_merge($this->optHeaders, (array)$value);
            return true;
        }
        return curl_setopt($this->curl, $name, $value);
    }

    /**
     * Get requests headers
     *
     * @return string
     */
    public function getRequestHeaders()
    {
        return curl_getinfo($this->curl, CURLINFO_HEADER_OUT);
    }

    /**
     * Close the opened resource
     */
    public function __destruct()
    {
        if ($this->curl) {
            curl_close($this->curl);
        }
    }
}
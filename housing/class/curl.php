<?php

class Curl {

    const DEFAULT_TIMEOUT = 30;

    public $curl;
    public $id = null;

    public $response = null;
    public $url = null;

    public $error = false;
    public $errorCode = 0;
    public $errorMessage = null;
    
    public $curlError = false;
    public $curlErrorCode = 0;
    public $curlErrorMessage = null;

    public $httpError = false;
    public $httpStatusCode = 0;
    public $httpErrorMesasge = null;
    
    /**
     * Construct
     *
     * @access public
     * @param  $base_url
     * @throws \ErrorException
     */
    public function __construct($base_url = null)
    {
        if (!extension_loaded('curl')) {
            throw new \ErrorException('cURL library is not loaded');
        }

        $this->curl = curl_init();
        $this->initialize($base_url);
    }

    /**
     * Close
     *
     * @access public
     */
    public function close()
    {
        if (is_resource($this->curl)) {
            curl_close($this->curl);
        }
    }

        /**
     * Exec
     *
     * @access public
     * @param  $ch
     *
     * @return mixed Returns the value provided by parseResponse.
     */
    public function exec($ch = null)
    {
        if ($ch === null) {
            $this->response = curl_exec($this->curl);
            $this->curlErrorCode = curl_errno($this->curl);
            $this->curlErrorMessage = curl_error($this->curl);
        } else {
            $this->response = curl_multi_getcontent($ch);
            $this->curlErrorMessage = curl_error($ch);
        }
        $this->curlError = $this->curlErrorCode !== 0;

        // Include additional error code information in error message when possible.
        if ($this->curlError && function_exists('curl_strerror')) {
            $this->curlErrorMessage =
                curl_strerror($this->curlErrorCode) . (
                    empty($this->curlErrorMessage) ? '' : ': ' . $this->curlErrorMessage
                );
        }

        $this->httpStatusCode = $this->getInfo(CURLINFO_HTTP_CODE);
        $this->httpError = in_array(floor($this->httpStatusCode / 100), array(4, 5));
        $this->error = $this->curlError || $this->httpError;
        $this->errorCode = $this->error ? ($this->curlError ? $this->curlErrorCode : $this->httpStatusCode) : 0;

        $this->errorMessage = $this->curlError ? $this->curlErrorMessage : $this->httpErrorMessage;

        // Reset nobody setting possibly set from a HEAD request.
        $this->setOpt(CURLOPT_NOBODY, false);

        $this->execDone();

        return $this->response;
    }
}

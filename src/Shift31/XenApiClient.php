<?php


namespace Shift31;


class XenApiClient
{
	/**
	 * @var string
	 */
	protected $_hostname;

	/**
	 * @var string
	 */
	protected $_username;

	/**
	 * @var string
	 */
	protected $_password;

	/**
	 * @var string
	 */
	protected $_clientApiVersion;

	/**
	 * @var bool
	 */
	protected $_useSSL;

	/**
	 * @var null|string
	 */
	protected $_sessionId = null;

	/**
	 * @var int
	 */
	protected $_invalidSessionCount = 0;


	const MAX_INVALID_SESSIONS = 3;


	/**
	 * @param string $hostname
	 * @param string $username
	 * @param string $password
	 * @param string $clientApiVersion
	 * @param bool   $useSSL
	 */
	public function __construct($hostname, $username, $password, $clientApiVersion = '1.3', $useSSL = true)
	{
		$this->_hostname = $hostname;
		$this->_username = $username;
		$this->_password = $password;
		$this->_clientApiVersion = $clientApiVersion;
		$this->_useSSL = $useSSL;

		$this->_login();
	}


	/**
	 * @throws \Exception
	 */
	protected function _login()
	{
		$response = $this->_doRequest(
			'session.login_with_password', array($this->_username, $this->_password, $this->_clientApiVersion)
		);

		if (is_array($response) && $response['Status'] == 'Success') {
			$this->_sessionId = $response['Value'];
		} else {
			throw new \Exception("XenAPI error: " . implode(' ', $response['ErrorDescription']));
		}
	}


	/**
	 * @param string $method
	 * @param mixed $params
	 *
	 * @return mixed
	 */
	protected function _doRequest($method, $params)
	{
		$postParams = xmlrpc_encode_request($method, $params);

		$ch = curl_init($this->_useSSL === true ? "https://$this->_hostname" : "http://$this->_hostname");

		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);

		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: text/xml', 'Content-length: ' . strlen($postParams)));

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

		curl_setopt($ch, CURLOPT_POSTFIELDS, $postParams);

		$response = curl_exec($ch);
		curl_close($ch);

		$decodedResponse = xmlrpc_decode($response);

		return $decodedResponse;
	}


	/**
	 * @param string $method
	 * @param array  $args
	 *
	 * @throws \Exception
	 * @return null|string
	 */
	public function call($method, array $args = array())
	{
		$response = $this->_doRequest($method, array_merge(array($this->_sessionId), $args));

		$responseValue = null;

		if (!@is_array($response) && !@$response['Status']) {
			throw new \Exception("XenAPI error: bad or null response");
		} else if ($response['Status'] == 'Success') {
			$responseValue = $response['Value'];
		} else if ($response['ErrorDescription'][0] == 'SESSION_INVALID') {
			if ($this->_invalidSessionCount <= self::MAX_INVALID_SESSIONS) {
				$this->_invalidSessionCount++;
				$this->_login();
				$this->call($method, $args);
			}
		} else {
			if ($response == null) {
				throw new \Exception("XenAPI error: null response...check hostname or connectivity");
			} else if (isset($response['ErrorDescription'])) {
				throw new \Exception("XenAPI error: " . implode(' ', $response['ErrorDescription']));
			} else {
				throw new \Exception("XenAPI error: unknown");
			}

		}

		return $responseValue;
	}


	public function __call($modifiedMethod, $args)
	{
		list($module, $method) = explode('_', $modifiedMethod, 2);

		$response = $this->call("$module.$method", $args);

		return $response;
	}


	public function __destruct()
	{
		if ($this->_sessionId != null) {
			$this->call('session.logout');
		}
	}

}
<?php
/*
 *	Name: Cloudfare DDNS
 *	Version: 1.0.1
 *	URI: https://github.com/jamesdlow/cloudflare-ddns
 *	Description: A simple PHP script that takes standard DDNS params and then updates the Cloudfare API
 *	Author: James Low
 *	Author URI: https://jameslow.com
 */

class CloudflareDDNS {
	public static function getClientIP() {
		if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			// Cloudflare Proxy
			return $_SERVER['HTTP_CF_CONNECTING_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// Proxy or Load Balancer
			return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
		} elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			// Shared Internet (e.g., VPNs)
			return $_SERVER['HTTP_CLIENT_IP'];
		} else {
			// Direct connection
			return $_SERVER['REMOTE_ADDR'];
		}
	}

	public static function getToken() {
		if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
			$authHeader = $_SERVER['HTTP_AUTHORIZATION']; // Get the Authorization header
		} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
			$authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; // Some servers use this instead
		} else {
			$authHeader = null;
		}
		
		if ($authHeader && strpos($authHeader, 'Basic ') === 0) {
			// Extract the base64-encoded credentials
			$encodedCreds = substr($authHeader, 6); // Remove "Basic " prefix
			$decodedCreds = base64_decode($encodedCreds); // Decode Base64
		
			// Split into username and password
			list($username, $password) = explode(':', $decodedCreds, 2);
			return $password;
		}
	}

	public static function cloudFlare($token, $path, $data = null, $method = null) {
		$curl = curl_init();
		$header = [
			"Authorization: Bearer $token",
			"Content-Type: application/json"
		];
		$json = '';
		if ($data != null) {
			$json = json_encode($data);
			$header[] =  "Content-Length: " . strlen($json);
			$method = $method ?? 'PUT';
			curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
		}
		if ($method) {
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		}
		curl_setopt($curl, CURLOPT_URL, 'https://api.cloudflare.com/client/v4'.$path);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
		$response = curl_exec($curl);
		curl_close($curl);
		return json_decode($response, true);
	}

	public static function getZones($token) {
		return self::cloudFlare($token, '/zones');
	}

	public static function getRecords($token, $id) {
		return self::cloudFlare($token, '/zones/'.$id.'/dns_records');
	}

	public static function updateRecord($token, $zone, $record, $name, $type, $content, $ttl = 1, $proxied = false) {
		$data = [
			'name' => $name,
			'type' => $type,
			'content' => $content,
			'ttl' => $ttl,
			'proxied' => false
		];
		return self::cloudFlare($token, '/zones/'.$zone.'/dns_records/'.$record, $data);
	}

	public static function errorResult($message, $result) {
		self::error($message, json_encode($result['errors']));
	}

	public static function error($message, $error) {
		error_log($error);
		self::end($message);
	}

	public static function end($message) {
		echo $message;
		exit();
	}

	public static function main() {
		//$username = $_GET['username'] ?? null;
		//$password = $_GET['password'] ?? null;
		$hostname = $_GET['hostname'] ?? null;
		if ($hostname) {
			$hostname = strtolower($hostname);
		} else {
			self::error('nohost', 'Hostname not specified');
		}
		$myip = $_GET['myip'] ?? self::getClientIP();
		$token = $_GET['password'] ?? self::getToken();
		if (!$token) {
			self::error('badauth', 'HTTP basic auth not provided');
		}
		$zones = self::getZones($token);
		if (isset($zones['result']) && isset($zones['success']) && $zones['success']) {
			foreach ($zones['result'] as $zone) {
				if (strpos($hostname, $zone['name']) >= 0) {
					$records = self::getRecords($token, $zone['id']);
					if (isset($records['result'])) {
						foreach ($records['result'] as $record) {
							if ($record['name'] == $hostname) {
								if ($record['content'] == $myip) {
									self::end('nochg '.$myip);
								} else {
									$update = json_encode(self::updateRecord($token,
										$zone['id'],
										$record['id'],
										$record['name'],
										$record['type'],
										$myip,
										$record['ttl'],
										$record['proxied']
									));
									if (isset($update['success']) && $update['success']) {
										self::end('good '.$myip);
									} else {
										self::errorResult('911', $update);
									}
								}
							}
						}
						self::error('nohost' ,'Record not found on Cloudflare');
					}
				} else {
					self::errorResult('911', $records);
				}
			}
			self::error('nohost', 'Domain not found on Cloudflare, or token not permissioned for domain');
		} else {
			self::errorResult('badauth', zones);
		}
	}
}

if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'])) {
	CloudflareDDNS::main();
}
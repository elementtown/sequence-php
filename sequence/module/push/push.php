<?php

namespace sequence\module\push {

	use sequence as s;
	use sequence\functions as f;

	class push extends s\module {

		use s\listener;

		private $response = [];

		/**
		 *
		 * @param string $request
		 * @param string $request_root
		 *
		 * @return int|null
		 */
		public function request($request, $request_root) {
			$root    = $this->root;
			$handler = $root->handler;

			header('Cache-Control: no-cache, no-store, must-revalidate');
			header('Expires: 0');

			$parts = explode('/', $request);

			$code = 404;

			if (count($parts) === 1) {
				$action = array_shift($parts);

				switch ($action) {
				case 'create':
				case 'register':
				case 'cancel':
				case 'send':
					$code = $this->$action();
				}
			}

			if ($code === 200) {
				$handler->setMethod(function () {
					echo json_encode($this->response);
				});

				$handler->setType('json');
			}

			return $code;
		}

		private function create() {
			$root     = $this->root;
			$database = $root->database;
			$prefix   = $database->getPrefix();

			$input = f\file_get_json('php://input');

			if (isset($input['device'])) {
				$statement = $database->prepare("
					select HEX(push_device)
					from {$prefix}push_devices
					where push_device = UNHEX(:device)
					limit 1
				");

				$statement->execute(['device' => $input['device']]);

				if ($row = $statement->fetch(\PDO::FETCH_NUM)) {
					$this->response = $row[0];

					return 200;
				}
			}

			do {
				$device = bin2hex(openssl_random_pseudo_bytes(144));

				$statement = $database->prepare("
					insert into {$prefix}push_devices
						(push_device)
					values	(UNHEX(:device))
				");
			} while (!$statement->execute(['device' => $device]));

			$this->response = $device;

			return 200;
		}

		private function register() {
			$root     = $this->root;
			$database = $root->database;
			$prefix   = $database->getPrefix();

			$input = f\file_get_json('php://input');

			if (!isset($input['notification']) || !isset($input['channel']) || !filter_var($input['channel'], FILTER_VALIDATE_URL)) {
				return 404;
			}

			if (!isset($input['device'])) {
				$input['device'] = null;
			}

			$trusted = 'notify.windows.com';
			$length  = strlen($trusted);

			$host = parse_url($input['channel'], PHP_URL_HOST);

			if (substr($host, -$length) != $trusted) {
				return 404;
			}

			$statement = $database->prepare("
				insert into {$prefix}push_general
					(push_device, push_notification, push_channel)
				values	(UNHEX(:device), :notification, :channel)
				on duplicate key update
					push_device = UNHEX(:device),
					push_channel = :channel
			");

			$statement->execute([
				'device'       => $input['device'],
				'notification' => $input['notification'],
				'channel'      => $input['channel']
			]);

			return 200;
		}

		private function cancel() {
			$root     = $this->root;
			$database = $root->database;
			$prefix   = $database->getPrefix();

			$input = f\file_get_json('php://input');

			if (!isset($input['notification'])) {
				return 404;
			}

			if (isset($input['device'])) {
				$statement = $database->prepare("
					delete from {$prefix}push_general
					where push_device = UNHEX(:device)
						and push_notification = :notification
				");

				$statement->execute([
					'device'       => $input['device'],
					'notification' => $input['notification']
				]);
			} else {
				if (isset($input['channel']) && filter_var($input['channel'], FILTER_VALIDATE_URL)) {
					$trusted = 'notify.windows.com';
					$length  = strlen($trusted);

					$host = parse_url($input['channel'], PHP_URL_HOST);

					if (substr($host, -$length) != $trusted) {
						return 404;
					}

					$statement = $database->prepare("
					delete from {$prefix}push_general
					where push_notification = :notification
						and push_channel = :channel
				");

					$statement->execute([
						'notification' => $input['notification'],
						'channel'      => $input['channel']
					]);
				} else {
					return 404;
				}
			}

			return 200;
		}

		private function send() {
			$root     = $this->root;
			$database = $root->database;
			$prefix   = $database->getPrefix();

			$input = f\file_get_json('php://input');

			if (!isset($input['token']) || !isset($input['message'])) {
				return 404;
			}

			$statement = $database->prepare("
				select push_notification
				from {$prefix}push_tokens
				where push_token = UNHEX(:token)
				limit 1
			");

			$statement->execute(['token' => $input['token']]);

			if ($row = $statement->fetch(\PDO::FETCH_NUM)) {
				$this->listen(function () use ($row, $input) {
					$this->notify($row[0], $input['message']);
				}, 'close', 'application');

				return 200;
			}

			return 404;
		}

		public function notify($notification, $message) {
			$root     = $this->root;
			$settings = $root->settings;
			$database = $root->database;
			$prefix   = $database->getPrefix();

			$token   = $settings["push_token_slack_$notification"];
			$payload = $settings["push_payload_slack_$notification"];

			if ($token !== null && $payload !== null) {
				$content = 'payload='.json_encode(f\json_decode($payload) + ['text' => $message]);

				$headers = [
					'Content-Length: '.strlen($content)
				];

				try {
					$context = stream_context_create([
						'http' => [
							'method'           => 'POST',
							'protocol_version' => '1.1',
							'header'           => implode("\r\n", $headers)."\r\n",
							'content'          => $content
						]
					]);

					$handle = fopen($token, 'rb', false, $context);

					if ($handle) {
						stream_get_contents($handle); // Discard response.
						fclose($handle);
					}
				} catch (\Exception $exception) {
					// Ignore.
				}
			}

			unset($token);

			$token = $settings['push_token_wns'];

			if ($token === null) {
				$token = $this->getToken();
			}

			if ($token === null) {
				return false;
			}

			$statement = $database->prepare("
				select HEX(push_device), push_channel
				from {$prefix}push_general
				where push_notification = :notification
				  and push_enabled = 1
			");

			$statement->execute(['notification' => $notification]);

			foreach ($statement->fetchAll() as $row) {
				push:
				$content = "<toast><visual><binding template=\"ToastText01\"><text id=\"1\">$message</text></binding></visual></toast>";

				$headers = [
					"Authorization: Bearer $token",
					'Content-Length: '.strlen($content),
					'Content-Type: text/xml',
					'X-WNS-Type: wns/toast'
				];

				try {
					$context = stream_context_create([
						'http' => [
							'method'           => 'POST',
							'protocol_version' => '1.1',
							'header'           => implode("\r\n", $headers)."\r\n",
							'content'          => $content
						]
					]);

					$handle = fopen($row[1], 'rb', false, $context);

					if ($handle) {
						$response = stream_get_contents($handle);
						fclose($handle);
					}
				} catch (\Exception $exception) {
					// Ignore.
				}

				$search = 'HTTP/1.1 ';
				$length = strlen($search);

				foreach ($http_response_header as $header) {
					if (substr($header, 0, $length) === $search) {
						$code = substr($header, $length, 3);

						if ($code === "401") {
							$token = $this->getToken();

							goto push;
						} elseif ($code === "410") {
							$statement = $database->prepare("
								delete from {$prefix}push_general
								where push_channel = :channel
							");

							$statement->execute(['channel' => $row[1]]);
						} elseif ($code !== "200") {
							$statement = $database->prepare("
								insert into {$prefix}push_errors
									(push_status, push_device, push_notification, push_channel,
									push_request_headers, push_request_body, push_response_headers, push_response_body)
								values (:status, UNHEX(:device), :notification, :channel, :request_headers, :request_body, :response_headers, :response_body)
							");

							$statement->execute([
								'status'           => $code,
								'device'           => $row[0],
								'notification'     => $notification,
								'channel'          => $row[1],
								'request_headers'  => implode("\n", $headers),
								'request_body'     => $content,
								'response_headers' => implode("\n", $http_response_header),
								'response_body'    => isset($response) ? $response : ''
							]);
						}

						break;
					}
				}
			}

			return true;
		}

		private function getToken() {
			$root     = $this->root;
			$settings = $root->settings;

			$host = 'login.live.com';

			$content = http_build_query([
				'grant_type'    => 'client_credentials',
				'client_id'     => $settings['push_wns_client_id'],
				'client_secret' => $settings['push_wns_client_secret'],
				'scope'         => 'notify.windows.com'
			]);

			$headers = [
				'Connection: close',
				'Content-Type: application/x-www-form-urlencoded'
			];

			$context = stream_context_create([
				'http' => [
					'method'           => 'POST',
					'protocol_version' => '1.1',
					'header'           => implode("\r\n", $headers)."\r\n",
					'content'          => $content
				]
			]);

			try {
				$json = file_get_contents("https://$host/accesstoken.srf", false, $context);
			} catch (\Exception $exception) {
				$json = false;
			}

			if ($json !== false) {
				$response = f\json_decode($json);

				if (isset($response['access_token'])) {
					$token = $response['access_token'];

					$settings->offsetStore('push_token_wns', $token);

					return $token;
				}
			}

			return null;
		}
	}
}

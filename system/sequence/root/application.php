<?php

namespace sequence\root {

	use sequence as b;

	/**
	 *
	 * @property-read array $debug
	 */
	class application {

		use b\broadcaster;

		/**
		 *
		 * @var array
		 */
		public static $messages = ['connect', 'module', 'template', 'close'];

		/**
		 *
		 * @var array
		 */
		public $errors = [];

		/**
		 *
		 * @var array
		 */
		public $settings = [];

		/**
		 *
		 * @var string
		 */
		protected $content;

		/**
		 *
		 * @var b\root
		 */
		protected $root;

		/**
		 *
		 * @var array
		 */
		private $debug = false;

		/**
		 *
		 * @param b\root $root
		 * @param string $binding
		 */
		public function __construct(b\root $root, $binding = '') {
			$this->root = $root;
		}

		/**
		 *
		 * @param string $name
		 *
		 * @return mixed
		 */
		public function __get($name) {
			switch ($name) {
				case 'debug':
					return $this->debug;
			}

			return null;
		}

		/**
		 *
		 * @param string  $systemPath
		 * @param array   $homePath
		 * @param array   $webPath
		 * @param boolean $finish
		 *
		 * @throws mixed
		 */
		public function routine($systemPath, $homePath, $webPath, $finish = true) {
			$this->setup($systemPath, $homePath, $webPath);

			// This must be done after setup.
			$root     = $this->root;
			$language = $root->language;
			$module   = $root->module;
			$template = $root->template;

			try {
				$level = ob_get_level();

				if ($this->errors) {
					$language->load();

					throw $this->errors[0];
				}

				$module->load();
				$language->load();

				$this->broadcast('module');
				$this->handler();

				if (b\ship) {
					$this->generate();
					$this->output();

					if ($finish && function_exists('fastcgi_finish_request')) {
						fastcgi_finish_request();
					}

					$this->broadcast('close');
				} else {
					if (ob_get_level() != $level) {
						throw new \Exception('OUTPUT_BUFFER_LEVEL_DIFFERS');
					}

					if (ob_get_length()) {
						throw new \Exception('OUTPUT_BUFFER_NOT_EMPTY');
					}

					$this->generate();

					$this->broadcast('close');

					if (ob_get_length()) {
						throw new \Exception('OUTPUT_BUFFER_NOT_EMPTY');
					}

					if (isset($template->variable['runtime'])) {
						$runtime = $template->variable['runtime'];

						header('X-Debug-Module-Runtime: ' . number_format($runtime) . utf8_decode('µs'));
					}

					if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
						$runtime = microtime(true) * 1e6 - $_SERVER['REQUEST_TIME_FLOAT'] * 1e6;

						header('X-Debug-Total-Runtime: ' . number_format($runtime) . utf8_decode('µs'));
					}

					$this->output();
					// We do not call fastcgi_finish_request() to ensure every bit of detail makes its way out.
				}
			} catch (\Exception $exception) {
				$template->error($exception);

				$this->generate();
				$this->output();
			}
		}

		/**
		 * @param $systemPath
		 * @param $homePath
		 * @param $webPath
		 *
		 * @throws \Exception
		 */
		public function setup($systemPath, $homePath, $webPath) {
			$root     = $this->root;
			$database = $root->database;
			$path     = $root->path;

			/*
			 * Set up output buffering.
			 *
			 * Output buffering is used to prevent accidental output.
			 * If there is any output, an error is thrown with the output dumped to the page in debug mode.
			 * If the output buffering level is different by the end of the script, an error is thrown.
			 */

			// Cancel the default output buffer (we recommend having it on despite cancelling it).
			if (ini_get('output_buffering') && ob_get_level() === 1) {
				if (ob_get_length()) {
					$buffer = ob_get_clean();
				} else {
					ob_end_clean();
				}
			}

			ob_start();

			if (isset($buffer)) {
				echo $buffer;

				unset($buffer);
			}

			/*
			 * Set up the error handler.
			 *
			 * We only deal with exceptions.
			 */

			set_error_handler(function ($errno, $errstr, $errfile, $errline) {
				throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
			});

			/*
			 * Set up debugging code.
			 *
			 * Include any debug files, define the sequence\debug constant, and define the sequence\ship constant.
			 */

			$debug = $systemPath . '/debug';

			if (is_dir($debug)) {
				define('sequence\\debug', true);

				$this->debug = [];

				foreach (glob($debug . '/*.php') as $file) {
					if (is_file($file)) {
						try {
							// Manually including each file as the namespace the classes are in would fool the autoloader.
							include $file;

							$class = 'sequence\\debug\\' . substr($file, strrpos($file, '/') + 1, -4);

							if (class_exists($class, false)) {
								// Instantiate the debug class.
								$this->debug[] = new $class($root);
							}
						} catch (\Exception $exception) {
							$this->errors[] = $exception;
						}
					}
				}

				unset($class);
			} else {
				define('sequence\\debug', false);
			}

			define('sequence\\ship', !b\debug);

			unset($debug);

			/*
			 * Bind this class for broadcasting and listening.
			 *
			 * This was performed late as it relies on the sequence\debug and sequence\ship constants to be defined.
			 */

			$this->bind($root);

			/*
			 * Include settings files.
			 */

			$settingsFile = $homePath . '/settings.php';

			if (is_file($settingsFile)) {
				try {
					$settings     = include $settingsFile;
				} catch (\Exception $exception) {
					$this->errors[] = $exception;

					$settings = [];
				}
			} else {
				$this->errors[] = new \Exception('SETTINGS_FILE_NOT_FOUND');

				$settings = [];
			}

			if (isset($settings['application']) && is_array($settings['application'])) {
				$this->settings = $settings['application'];
			}

			$this->settings += require $systemPath . '/settings.php';

			if (!isset($settings['path']) || !is_array($settings['path'])) {
				$settings['path'] = [];
			}

			// Set up our paths.
			$path->settings($systemPath, $homePath, $webPath, $settings['path']);

			if (isset($settings['database']) && is_array($settings['database'])) {
				try {
					// Open database connection.
					$database->connect($settings['database']);
				} catch (\Exception $exception) {
					$this->errors[] = $exception;
				}
			} else {
				$this->errors[] = new \Exception('DATABASE_CONNECTION_FAILED');
			}

			unset($settings);
		}

		/**
		 *
		 */
		public function handler() {
			$root     = $this->root;
			$handler  = $root->handler;
			$template = $root->template;

			// Parse the request.
			if ($handler->parse()) {
				$start = microtime(true) * 1e6;

				$handler->load();

				// Calculate the time it took to run the module.
				$template->variable['runtime'] = microtime(true) * 1e6 - $start;

				$this->broadcast('template');
			}
		}

		/**
		 *
		 */
		public function generate() {
			$root     = $this->root;
			$template = $root->template;

			$this->content = $template->body();

			// Prevent issues if we're debugging.
			if (b\ship) {
				$digest        = base64_encode(pack('H*', md5($this->content)));
				$this->content = gzencode($this->content, 9);

				header('Content-Encoding: gzip');
				header('Content-Length: ' . mb_strlen($this->content, '8bit'));
				header('Content-MD5: ' . $digest);
			}

			header('Content-Type: text/html; charset=utf-8');
			header('Last-Modified: ' . (new \DateTime('now', new \DateTimeZone('UTC')))->format('D, d M Y H:i:s T'));
		}

		/**
		 *
		 */
		public function output() {
			ob_end_clean();

			echo $this->content;
		}
	}
}

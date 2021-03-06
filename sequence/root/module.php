<?php

namespace sequence\root {

	use sequence as s;

	use arrayaccess, exception;

	class module implements arrayaccess {

		use s\broadcaster;

		/**
		 * List of messages that this class can send.
		 *
		 * @var array
		 */
		const messages = [];

		/**
		 * Loaded modules.
		 *
		 * @var array
		 */
		private $container = [];

		/**
		 * Basic constructor.
		 *
		 * @param s\root $root
		 * @param string $binding
		 */
		public function __construct(s\root $root, $binding = '') {
			$this->bind($root, $binding);
		}

		/**
		 * Bind all classes in root to application identity.
		 *
		 * @return string
		 */
		protected function getBinding() {
			return 'application';
		}

		/**
		 * Load all enabled modules.
		 */
		public function load() {
			$root     = $this->root;
			$database = $root->database;
			$prefix   = $database->getPrefix();

			$statement = $database->prepare("
				select module_name
				from {$prefix}modules
				where module_is_enabled = 1
			");

			$statement->execute();

			foreach ($statement->fetchAll() as $row) {
				$class = "sequence\\module\\$row[0]\\$row[0]";

				spl_autoload($class);
				$this->container[$row[0]] = new $class($root);
			}
		}

		/**
		 * Get all successfully loaded module instances.
		 *
		 * @return array
		 */
		public function getLoaded() {
			return $this->container;
		}

		/*
		 * Implementation of \ArrayAccess.
		 */

		/**
		 * Check if the module was successfully loaded.
		 *
		 * @param string $offset
		 *
		 * @return boolean
		 */
		public function offsetExists($offset) {
			return isset($this->container[(string)$offset]);
		}

		/**
		 * Get the module instance.
		 *
		 * @param string $offset
		 *
		 * @return s\module|null
		 */
		public function offsetGet($offset) {
			$offset = (string)$offset;

			if (isset($this->container[$offset])) {
				return $this->container[$offset];
			} else {
				return null;
			}
		}

		/**
		 * Overriding module instances at runtime is not supported.
		 *
		 * @param string $offset
		 * @param string $value
		 *
		 * @throws exception
		 */
		public function offsetSet($offset, $value) {
			throw new exception('METHOD_NOT_SUPPORTED');
		}

		/**
		 * Overriding module instances at runtime is not supported.
		 *
		 * @param string $offset
		 *
		 * @throws exception
		 */
		public function offsetUnset($offset) {
			throw new exception('METHOD_NOT_SUPPORTED');
		}

		/*
		 * End implementation of \ArrayAccess.
		 */
	}
}

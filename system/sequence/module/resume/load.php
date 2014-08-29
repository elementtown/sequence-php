<?php

namespace sequence\module\resume {

	use sequence as b;

	class load {

		use b\listener;

		/**
		 *
		 * @param b\root $root
		 * @param string $binding
		 */
		public function __construct(b\root $root, $binding = '') {
			$this->bind($root, $binding);
		}

		public function request($request) {
			$this->root->template->file = 'resume/index';
		}
	}
}

<?php declare(strict_types=1);
	/**
	 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
	 *
	 * Unauthorized copying of this file, via any medium, is
	 * strictly prohibited without consent. Any dissemination of
	 * material herein is prohibited.
	 *
	 * For licensing inquiries email <licensing@apisnetworks.com>
	 *
	 * Written by Matt Saladna <matt@apisnetworks.com>, March 2019
	 */

	namespace Opcenter\Dns\Providers\Powerdns;

	class Record extends \Opcenter\Dns\Record
	{
		protected $ttl = Module::DNS_TTL;

		public function __construct(string $zone, array $args)
		{

			parent::__construct($zone, $args);
		}

		protected function formatCname()
		{
			return $this->appendDot();
		}

		protected function formatAlias()
		{
			return $this->appendDot();
		}

		protected function formatDname()
		{
			return $this->appendDot();
		}

		protected function formatNs()
		{
			return $this->appendDot();
		}

		protected function formatMx()
		{
			$this->parameter = str_replace("\t", ' ', $this->parameter);

			return $this->appendDot();
		}

		protected function formatSpf()
		{
			$this->parameter = trim($this->parameter, '"');
			$this->parameter = '"' . $this->parameter . '"';
		}

		protected function formatSrv()
		{
			return $this->appendDot();
		}

		protected function formatSoa()
		{
			$this->parameter = implode(' ',
				[
					rtrim($this->getMeta('mname'), '.') . '.',
					rtrim($this->getMeta('rname'), '.') . '.',
					$this->getMeta('serial'),
					$this->getMeta('refresh'),
					$this->getMeta('retry'),
					$this->getMeta('expire'),
					$this->getMeta('ttl'),
				]
			);
		}

		protected function formatTxt() {
			// PowerDNS requires all space-delimited sets of TXT records to be quoted
			// ensure last segment has terminating quotes
			$this->parameter = (string)preg_replace('/^((?:(?:"(?>[^"]+)(?>"\s*)))*)(?!")(.+)$/', '\1"\2"', $this->parameter);
			if ($this->parameter && $this->parameter[0] === '"' && $this->parameter[0] === $this->parameter[-1]) {
				$this->parameter = '"' . trim($this->parameter, '"') . '"';
			}
		}

		protected function formatUri()
		{
			$this->setMeta('data', '"' . trim($this->getMeta('data'), '"') . '"');
			$this->parameter = implode(' ',
				[
					$this->getMeta('priority'),
					$this->getMeta('weight'),
					$this->getMeta('data')
				]
			);
		}

		private function appendDot()
		{
			if (null !== ($data = $this->getMeta('data'))) {
				$this->setMeta('data', rtrim($data, '.') . '.');
			}
			$this->parameter = rtrim($this->parameter, '.') . '.';
		}
	}
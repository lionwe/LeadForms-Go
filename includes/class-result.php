<?php

declare(strict_types=1);

namespace LeadFormsGo;

final readonly class Result
{
	public function __construct(
		public bool $success,
		public int $http_code = 0,
		public string $message = '',
		public ?bool $retryable = null,
		public string $external_reference = ''
	) {}
}

<?php

declare(strict_types=1);

namespace LeadFormsGo;

interface Connector_Interface
{
	public function key(): string;
	public function is_enabled(): bool;
	public function validate_settings(): true|\WP_Error;
	public function test_connection(): Result;
	public function send(array $data, string $referer): Result;
}


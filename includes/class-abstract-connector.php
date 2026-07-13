<?php

declare(strict_types=1);

namespace LeadFormsGo;

abstract class Abstract_Connector implements Connector_Interface
{
	protected const REQUEST_TIMEOUT = 12;
	protected const RESPONSE_SIZE_LIMIT = 262144;

	protected function settings(): array
	{
		return Settings::section($this->key());
	}

	public function is_enabled(): bool
	{
		return ! empty($this->settings()['enabled']);
	}

	protected function result(mixed $response, string $external_reference = ''): Result
	{
		if (is_wp_error($response)) return new Result(false, 0, __('Не вдалося з’єднатися з віддаленим сервісом.', 'leadforms-go'), true);
		$code = wp_remote_retrieve_response_code($response);
		$success = $code >= 200 && $code < 300;
		$retryable = ! $success && ($code === 408 || $code === 425 || $code === 429 || $code >= 500);
		return new Result($success, $code, $success ? '' : __('Віддалений сервіс відхилив запит.', 'leadforms-go'), $retryable, $success ? $external_reference : '');
	}

	protected function request_args(array $args = []): array
	{
		return array_replace([
			'timeout' => self::REQUEST_TIMEOUT,
			'redirection' => 0,
			'limit_response_size' => self::RESPONSE_SIZE_LIMIT,
			'sslverify' => true,
		], $args);
	}
}

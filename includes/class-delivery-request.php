<?php

declare(strict_types=1);

namespace LeadFormsGo;

final readonly class Delivery_Request
{
	public function __construct(
		public int $delivery_id,
		public int $submission_id,
		public int $form_id,
		public string $locale,
		public array $payload,
		public string $referer,
		public array $route
	) {}

	public function variables(): array
	{
		$form = $this->form_id > 0 ? Repositories::form($this->form_id) : null;
		$context = is_array($this->route['_context'] ?? null) ? $this->route['_context'] : [];
		$variables = $this->payload;
		$variables['page_url'] = $this->referer;
		$variables['form_name'] = (string) ($context['form_name'] ?? (is_array($form) ? ($form['name'] ?? '') : ''));
		$variables['submitted_at'] = (string) ($context['submitted_at'] ?? current_time('mysql'));
		$variables['locale'] = $this->locale;
		return Submission_Validator::sanitize_payload($variables);
	}
}

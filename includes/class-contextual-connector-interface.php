<?php

declare(strict_types=1);

namespace LeadFormsGo;

interface Contextual_Connector_Interface extends Connector_Interface
{
	public function validate_route(array $route): true|\WP_Error;

	public function test_route(array $route, array $payload, int $form_id, string $locale): Result;

	public function send_request(Delivery_Request $request): Result;
}

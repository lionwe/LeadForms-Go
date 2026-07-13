<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Connectors
{
	/** @return array<string, Connector_Interface> */
	public static function all(): array
	{
		$connectors = [new Telegram_Connector(), new Sheets_Connector(), new Crm_Connector()];
		$indexed = [];
		foreach ($connectors as $connector) $indexed[$connector->key()] = $connector;
		$filtered = apply_filters('leadforms_go_connectors', $indexed);
		if (! is_array($filtered)) return $indexed;
		$valid = [];
		foreach ($filtered as $connector) {
			if (! $connector instanceof Connector_Interface) continue;
			$key = sanitize_key($connector->key());
			if ($key !== '') $valid[$key] = $connector;
		}
		return $valid;
	}
}

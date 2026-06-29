<?php
declare(strict_types=1);

// Load application functions used by unit tests.
if (!function_exists('esc')) {
	function esc(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/migrations.php';

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
require_once __DIR__ . '/../core/chat_utils.php';
require_once __DIR__ . '/../core/product_display_utils.php';
require_once __DIR__ . '/../core/finance_utils.php';
require_once __DIR__ . '/../core/pickup_offer_utils.php';
require_once __DIR__ . '/../core/settlement_utils.php';
require_once __DIR__ . '/../core/purchase_order_utils.php';

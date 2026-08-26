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
require_once __DIR__ . '/../core/phone_utils.php';
require_once __DIR__ . '/../core/migrations.php';
require_once __DIR__ . '/../core/chat_utils.php';
require_once __DIR__ . '/../core/product_display_utils.php';
require_once __DIR__ . '/../core/catalogo_utils.php';
require_once __DIR__ . '/../core/finance_utils.php';
require_once __DIR__ . '/../core/pickup_offer_utils.php';
require_once __DIR__ . '/../core/settlement_utils.php';
require_once __DIR__ . '/../core/purchase_order_utils.php';
require_once __DIR__ . '/../core/whatsapp_helper.php';
require_once __DIR__ . '/../core/ai_assistant.php';
require_once __DIR__ . '/../core/whatsapp_link_utils.php';
require_once __DIR__ . '/../core/entrega_item_utils.php';
require_once __DIR__ . '/../core/cliente_direccion_utils.php';
require_once __DIR__ . '/../core/pedido_item_admin_utils.php';
require_once __DIR__ . '/../core/alex_insights_utils.php';
require_once __DIR__ . '/../core/cliente_loyalty_utils.php';
require_once __DIR__ . '/../core/sale_inventory_bypass_utils.php';

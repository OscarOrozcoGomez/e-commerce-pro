<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/delivery_route_utils.php';

use PHPUnit\Framework\TestCase;

final class DeliveryRoutePreferencesTest extends TestCase
{
    public function testDeliveryParseDeliveryPreferencesNormalizesDayAndTimeFields(): void
    {
        $preferences = deliveryParseDeliveryPreferences([
            'ventanas' => [
                ['dia' => 'lunes', 'inicio' => '09:00', 'fin' => '16:00'],
                ['day' => 'martes', 'start' => '13:00', 'end' => '21:00'],
            ],
            'horario_regular' => ['inicio' => '08:00', 'fin' => '18:00'],
        ]);

        $this->assertCount(2, $preferences['ventanas']);
        $this->assertSame('09:00', $preferences['ventanas'][0]['start']);
        $this->assertSame('16:00', $preferences['ventanas'][0]['end']);
        $this->assertSame('martes', $preferences['ventanas'][1]['day']);
        $this->assertSame('08:00', $preferences['default_window']['start']);
        $this->assertSame('18:00', $preferences['default_window']['end']);
    }

    public function testDeliveryOrderStopsByWindowPriorityPrioritizesEarlierClosingWindows(): void
    {
        $stops = [
            [
                'id_pedido' => 2,
                'delivery_preferences' => [
                    'ventanas' => [['dia' => 'lunes', 'inicio' => '12:00', 'fin' => '21:00']],
                ],
            ],
            [
                'id_pedido' => 1,
                'delivery_preferences' => [
                    'ventanas' => [['dia' => 'lunes', 'inicio' => '10:00', 'fin' => '15:00']],
                ],
            ],
        ];

        $ordered = deliveryOrderStopsByWindowPriority(
            $stops,
            new DateTimeImmutable('2026-08-10 09:00:00')
        );

        $this->assertSame([1, 2], array_map(static fn (array $stop): int => (int) $stop['id_pedido'], $ordered));
    }

    public function testDeliveryManualInputValidationAcceptsRealTimesAndRejectsBadValues(): void
    {
        $this->assertSame('09:30', deliveryNormalizeManualHour('9:30'));
        $this->assertSame('18:45', deliveryNormalizeManualHour('18:45'));
        $this->assertSame('miercoles', deliveryNormalizeDeliveryDay('miércoles'));

        $this->expectException(InvalidArgumentException::class);
        deliveryNormalizeManualHour('99:99');
    }
}

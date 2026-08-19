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

    public function testDeliveryManualInputValidationAcceptsPositiveCases(): void
    {
        $this->assertSame('09:30', deliveryNormalizeManualHour('9:30'));
        $this->assertSame('18:45', deliveryNormalizeManualHour('18:45'));
        $this->assertSame('09:30', deliveryNormalizeManualHour('9:30 AM'));
        $this->assertSame('22:15', deliveryNormalizeManualHour('10:15 PM'));
        $this->assertSame('00:00', deliveryNormalizeManualHour('12:00 AM'));
        $this->assertSame('12:00', deliveryNormalizeManualHour('12:00 PM'));
        $this->assertSame('01:05', deliveryNormalizeManualHour('1:05 AM'));
        $this->assertSame('12:00', deliveryNormalizeManualHour('12:00'));
    }

    public function testDeliveryManualInputValidationRejectsNegativeCases(): void
    {
        $this->expectException(InvalidArgumentException::class);
        deliveryNormalizeManualHour('99:99');

        $this->expectException(InvalidArgumentException::class);
        deliveryNormalizeManualHour('25:00 PM');

        $this->expectException(InvalidArgumentException::class);
        deliveryNormalizeManualHour('9:60 AM');
    }

    public function testDeliveryManualInputValidationHandlesEdgeCasesForDayNormalization(): void
    {
        $this->assertSame('miercoles', deliveryNormalizeDeliveryDay('miércoles'));
        $this->assertSame('domingo', deliveryNormalizeDeliveryDay('DOM'));
        $this->assertSame('lunes', deliveryNormalizeDeliveryDay('LUNES'));
    }

    public function testDeliveryFormatAddressAliasUsesFriendlyFallbacks(): void
    {
        $this->assertSame('Casa', deliveryFormatAddressAlias('Casa'));
        $this->assertSame('Domicilio 1', deliveryFormatAddressAlias('', 1));
        $this->assertSame('Domicilio 3', deliveryFormatAddressAlias('   ', 3));
        $this->assertSame('Domicilio 2', deliveryFormatAddressAlias('Direccion 337', 2));
    }

    public function testDeliveryOrderStopsByWindowPriorityPlacesUnscheduledBetweenWindowsWhenSlackAllows(): void
    {
        $departure = new DateTimeImmutable('2026-08-10 09:00:00'); // lunes
        $origin = ['lat' => 20.6596988, 'lng' => -103.3496092];

        $stops = [
            [
                'id_pedido' => 100,
                'lat' => 20.6645,
                'lng' => -103.3550,
                'tiempo_servicio_min' => 5,
                'delivery_preferences' => ['ventanas' => [['dia' => 'lunes', 'inicio' => '16:00', 'fin' => '17:00']]],
            ],
            [
                'id_pedido' => 200,
                'lat' => 20.6620,
                'lng' => -103.3520,
                'tiempo_servicio_min' => 5,
                'delivery_preferences' => ['ventanas' => []],
            ],
            [
                'id_pedido' => 300,
                'lat' => 20.6710,
                'lng' => -103.3600,
                'tiempo_servicio_min' => 5,
                'delivery_preferences' => ['ventanas' => [['dia' => 'lunes', 'inicio' => '20:00', 'fin' => '21:00']]],
            ],
        ];

        $ordered = deliveryOrderStopsByWindowPriority($stops, $departure, $origin);
        $ids = array_map(static fn(array $stop): int => (int)$stop['id_pedido'], $ordered);

        $this->assertSame([200, 100, 300], $ids);
    }

    public function testDeliveryOrderStopsByWindowPriorityKeepsUrgentWindowBeforeUnscheduledWhenNoSlack(): void
    {
        $departure = new DateTimeImmutable('2026-08-10 16:50:00'); // lunes
        $origin = ['lat' => 20.6596988, 'lng' => -103.3496092];

        $stops = [
            [
                'id_pedido' => 10,
                'lat' => 20.6610,
                'lng' => -103.3505,
                'tiempo_servicio_min' => 5,
                'delivery_preferences' => ['ventanas' => [['dia' => 'lunes', 'inicio' => '16:00', 'fin' => '17:00']]],
            ],
            [
                'id_pedido' => 20,
                'lat' => 20.6800,
                'lng' => -103.3800,
                'tiempo_servicio_min' => 5,
                'delivery_preferences' => ['ventanas' => []],
            ],
        ];

        $ordered = deliveryOrderStopsByWindowPriority($stops, $departure, $origin);
        $ids = array_map(static fn(array $stop): int => (int)$stop['id_pedido'], $ordered);

        $this->assertSame([10, 20], $ids);
    }

    public function testDeliveryValidateFechaEntregaAsignacionRejectsEmptyFecha(): void
    {
        $result = deliveryValidateFechaEntregaAsignacion('');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['fecha']);
        $this->assertSame(
            'Debes seleccionar una fecha de entrega antes de asignar el pedido a un repartidor.',
            $result['error']
        );
    }

    public function testDeliveryValidateFechaEntregaAsignacionRejectsWhitespaceOnlyFecha(): void
    {
        $result = deliveryValidateFechaEntregaAsignacion('   ');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['fecha']);
    }

    public function testDeliveryValidateFechaEntregaAsignacionRejectsInvalidFecha(): void
    {
        $result = deliveryValidateFechaEntregaAsignacion('31/12/2026');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['fecha']);
        $this->assertSame('La fecha de entrega no es valida.', $result['error']);
    }

    public function testDeliveryValidateFechaEntregaAsignacionRejectsOutOfRangeDate(): void
    {
        // createFromFormat es laxo y "rollea" fechas invalidas (13/45) a otro mes/dia valido;
        // esta prueba confirma que la validacion detecta ese caso en vez de aceptarlo en silencio.
        $result = deliveryValidateFechaEntregaAsignacion('2026-13-45');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['fecha']);
    }

    public function testDeliveryValidateFechaEntregaAsignacionAcceptsValidFecha(): void
    {
        $result = deliveryValidateFechaEntregaAsignacion('2026-08-19');

        $this->assertTrue($result['valid']);
        $this->assertSame('2026-08-19 00:00:00', $result['fecha']);
        $this->assertNull($result['error']);
    }
}

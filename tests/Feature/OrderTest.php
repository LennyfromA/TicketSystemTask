<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function testStoreOrderSuccess()
    {
        Http::fake([
            'https://api.site.com/book' => Http::response(['message' => 'order successfully booked'], 200),
            'https://api.site.com/approve' => Http::response(['message' => 'order successfully approved'], 200),
        ]);

        $response = $this->postJson('/storeOrder', [
            'event_id' => '003',
            'event_date' => '2021-08-21 13:00:00',
            'ticket_adult_price' => 700,
            'ticket_adult_quantity' => 1,
            'ticket_kid_price' => 450,
            'ticket_kid_quantity' => 0,
            'user_id' => '00451',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'order successfully approved']);
    }

    public function testStoreOrderWithBookingError()
    {
        Http::fake([
            'https://api.site.com/book' => Http::response(['error' => 'barcode already exists'], 400),
        ]);

        $response = $this->postJson('/storeOrder', [
            'event_id' => '003',
            'event_date' => '2021-08-21 13:00:00',
            'ticket_adult_price' => 700,
            'ticket_adult_quantity' => 1,
            'ticket_kid_price' => 450,
            'ticket_kid_quantity' => 0,
            'user_id' => '00451',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Заказ отменён']);
    }

    public function testStoreOrderWithRandomApprovalErrors()
    {
        // Возможные сообщения об ошибках, которые возвращаются от API для одобрения
        $errorMessages = [
            'event cancelled' => 'Event was cancelled',
            'no tickets' => 'No tickets available',
            'no seats' => 'No seats available',
            'fan removed' => 'Fan was removed',
        ];

        // Используем Http::sequence() для создания предсказуемой последовательности ответов
        $sequence = Http::sequence();
        foreach ($errorMessages as $errorCode => $errorMessage) {
            $sequence->push(['message' => $errorCode], 400);
        }

        // Имитация успешного бронирования, после чего API одобрения вернёт случайное сообщение об ошибке
        Http::fake([
            'https://api.site.com/book' => Http::response(['message' => 'order successfully booked'], 200),
            'https://api.site.com/approve' => $sequence,
        ]);

        foreach ($errorMessages as $errorCode => $errorMessage) {
            $response = $this->postJson('/storeOrder', [
                'event_id' => '003',
                'event_date' => '2021-08-21 13:00:00',
                'ticket_adult_price' => 700,
                'ticket_adult_quantity' => 1,
                'ticket_kid_price' => 450,
                'ticket_kid_quantity' => 0,
                'user_id' => '00451',
            ]);

            $response->assertStatus(400);
            // Проверяем, что возвращённое сообщение об ошибке содержится в массиве возможных сообщений
            $this->assertContains($response->json('message'), $errorMessages);
        }
    }
}

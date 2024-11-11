<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Контроллер для управления заказами.
 */
class OrderController extends Controller
{
    /**
     * Сохраняет заказ, отправляет запрос на бронирование и одобрение заказа.
     *
     * @param Request $request HTTP-запрос с данными о заказе
     * @return \Illuminate\Http\JsonResponse JSON-ответ с результатом операции
     */
    /**
     * @OA\Post(
     *     path="/api/storeOrder",
     *     summary="Создание и бронирование заказа с последующим одобрением",
     *     description="Создаёт заказ, бронирует его через внешний API, и отправляет запрос на одобрение. В случае успеха заказ сохраняется.",
     *     tags={"Orders"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"event_id", "event_date", "ticket_adult_price", "ticket_adult_quantity", "ticket_kid_price", "ticket_kid_quantity", "user_id"},
     *             @OA\Property(property="event_id", type="integer", description="ID мероприятия"),
     *             @OA\Property(property="event_date", type="string", format="date-time", description="Дата и время мероприятия"),
     *             @OA\Property(property="ticket_adult_price", type="number", format="float", description="Стоимость взрослого билета"),
     *             @OA\Property(property="ticket_adult_quantity", type="integer", description="Количество взрослых билетов"),
     *             @OA\Property(property="ticket_kid_price", type="number", format="float", description="Стоимость детского билета"),
     *             @OA\Property(property="ticket_kid_quantity", type="integer", description="Количество детских билетов"),
     *             @OA\Property(property="user_id", type="integer", description="ID пользователя, создавшего заказ")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Заказ успешно одобрен и сохранен",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="order successfully approved")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Ошибка бронирования или одобрения",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="No seats available")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Ошибка сервера при бронировании",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Failed to book the order")
     *         )
     *     )
     * )
     */
    public function storeOrder(Request $request)
    {
        // Рассчитываем общую стоимость взрослых и детских билетов.
        $equalPrice = ($request->ticket_adult_price * $request->ticket_adult_quantity) +
                                                            ($request->ticket_kid_price * $request->ticket_kid_quantity);

        // Генерируем уникальный штрихкод для каждого заказа.
        do {
            $barcode = str_pad(rand(1, 99999999), 8, '0', STR_PAD_LEFT);
        } while (Order::where('barcode', $barcode)->exists());

        // Создаем запись заказа в базе данных.
        $order = new Order([
            'event_id' => $request->event_id,
            'event_date' => $request->event_date,
            'ticket_adult_price' => $request->ticket_adult_price,
            'ticket_adult_quantity' => $request->ticket_adult_quantity,
            'ticket_kid_price' => $request->ticket_kid_price,
            'ticket_kid_quantity' => $request->ticket_kid_quantity,
            'barcode' => $barcode,
            'user_id' => $request->user_id,
            'equal_price' => $equalPrice,
        ]);

        // Запрос на бронирование билетов через внешний API.
        $bookingResult = $this->bookApi($order, $request);

        // Проверка успешного бронирования и отправка на одобрение.
        if ($bookingResult['success']) {
            $approvalResult = $this->aprApi($order->barcode);

            if ($approvalResult['success']) {

                $order->status = 'approved';
                $order->save(); //при успешном одобрении заказа со стороны API.

                return response()->json(['message' => 'order successfully approved'], 200);
            } else {
                return response()->json(['message' => $approvalResult['message']], 400);
            }
        } else {
            return response()->json(['message' => 'Заказ отменён'], 400);
        }
    }

    /**
     * Отправляет запрос на бронирование билетов на внешнем API.
     * При ошибке генерации штрихкода пытается сгенерировать новый штрихкод.
     *
     * @param Order $order Объект заказа
     * @param Request $request HTTP-запрос с данными о заказе
     * @return array Результат бронирования (успех/ошибка и сообщение)
     */
    public function bookApi(Order $order, Request $request)
    {
        $maxAttempts = 3;
        $attempts = 0;
        do {
            // Отправляем запрос на бронирование на указанный API.
            $response = Http::post('https://api.site.com/book', [
                'event_id' => $request->event_id,
                'event_date' => $request->event_date,
                'ticket_adult_price' => $request->ticket_adult_price,
                'ticket_adult_quantity' => $request->ticket_adult_quantity,
                'ticket_kid_price' => $request->ticket_kid_price,
                'ticket_kid_quantity' => $request->ticket_kid_quantity,
                'barcode' => $order->barcode,
            ]);

            $responseData = $response->json();

            if (isset($responseData['error']) && $responseData['error'] === 'barcode already exists') {
                do {
                    $order->barcode = str_pad(rand(1, 99999999), 8, '0', STR_PAD_LEFT);
                } while (Order::where('barcode', $order->barcode)->exists());
                $attempts++;
            } else {
                return ['success' => true, 'message' => 'Order successfully booked'];
            }
        } while ($attempts < $maxAttempts);

        return ['success' => false, 'message' => 'Failed to book the order'];
    }

    /**
     * Отправляет запрос на одобрение бронирования на внешнем API.
     * Возвращает результат одобрения или сообщение об ошибке.
     *
     * @param string $barcode Уникальный номер заказа
     * @return array Результат одобрения (успех/ошибка и сообщение)
     */
    public function aprApi($barcode)
    {
        // Отправляем запрос на одобрение заказа.
        $response = Http::post('https://api.site.com/approve', [
            'barcode' => $barcode,
        ]);

        $responseData = $response->json();

        // Проверяем успешное одобрение заказа.
        if (isset($responseData['message']) && $responseData['message'] === 'order successfully approved') {
            return ['success' => true, 'message' => 'Order successfully approved'];
        } else {
            // Возможные ошибки при одобрении и случайный выбор для демонстрации возможного отказа.
            $errorMessages = [
                'event cancelled' => 'Event was cancelled',
                'no tickets' => 'No tickets available',
                'no seats' => 'No seats available',
                'fan removed' => 'Fan was removed',
            ];

            $randomErrorKey = array_rand($errorMessages);
            $randomErrorMessage = $errorMessages[$randomErrorKey];

            return ['success' => false, 'message' => $randomErrorMessage];
        }
    }
}
